<?php

namespace App\Queue;

use Aws\Sqs\SqsClient;
use GuzzleHttp\Promise\Each;
use Illuminate\Bus\DebounceLock;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\SqsQueue;
use Illuminate\Support\Str;
use RuntimeException;

class BatchSqsQueue extends SqsQueue
{
    /**
     * SQS limit: 10 messages per SendMessageBatch request.
     */
    private const MAX_BATCH_COUNT = 10;

    /**
     * SQS limit: 1 MiB (1,048,576 bytes) maximum POST body per SendMessageBatch
     * request. This bounds the serialized request JSON, which also satisfies
     * the documented 1 MiB cap on the sum of the raw message bodies.
     */
    private const MAX_POST_BYTES = 1_048_576;

    public function __construct(
        SqsClient $sqs,
        $default,
        $prefix = '',
        $suffix = '',
        $dispatchAfterCommit = false,
        protected int $maxConcurrentBatches = 50,
    ) {
        parent::__construct($sqs, $default, $prefix, $suffix, $dispatchAfterCommit);
    }

    /**
     * Push an array of jobs onto the queue using SQS SendMessageBatch.
     *
     * Per-job behaviour mirrors singular dispatch: JobQueueing/JobQueued events
     * are raised, ShouldQueueAfterCommit / dispatchAfterCommit defers sending
     * until commit, ShouldBeUnique and debounce locks are released on rollback,
     * and per-job $job->delay is honoured.
     *
     * @param  iterable<int, mixed>  $jobs
     * @param  mixed  $data
     * @param  string|null  $queue
     */
    public function bulk($jobs, $data = '', $queue = null): void
    {
        $jobs = (array) $jobs;

        if (empty($jobs)) {
            return;
        }

        $queue = $this->getQueue($queue);

        [$deferred, $immediate] = $this->partitionByAfterCommit($jobs);

        if (! empty($deferred)) {
            $transactions = $this->container->make('db.transactions');

            foreach ($deferred as $job) {
                $this->registerRollbackCallbacks($job);
            }

            $transactions->addCallback(
                fn () => $this->dispatchBulk($deferred, $data, $queue),
            );
        }

        if (! empty($immediate)) {
            $this->dispatchBulk($immediate, $data, $queue);
        }
    }

    /**
     * Split jobs by whether they should be dispatched after the current DB
     * transaction commits.
     *
     * @param  array<int, mixed>  $jobs
     * @return array{0: array<int, mixed>, 1: array<int, mixed>}
     */
    protected function partitionByAfterCommit(array $jobs): array
    {
        $hasTransactions = $this->container->bound('db.transactions');

        $deferred = [];
        $immediate = [];

        foreach ($jobs as $job) {
            if ($hasTransactions && $this->shouldDispatchAfterCommit($job)) {
                $deferred[] = $job;
            } else {
                $immediate[] = $job;
            }
        }

        return [$deferred, $immediate];
    }

    /**
     * Register rollback callbacks so unique / debounce locks held by a deferred
     * job are released if the transaction rolls back.
     */
    protected function registerRollbackCallbacks(object $job): void
    {
        $transactions = $this->container->make('db.transactions');

        if ($job instanceof ShouldBeUnique) {
            $transactions->addCallbackForRollback(function () use ($job) {
                (new UniqueLock($this->container->make(Cache::class)))->release($job);
            });
        }

        if (! empty($job->debounceOwner ?? '')) {
            $transactions->addCallbackForRollback(function () use ($job) {
                (new DebounceLock($this->container->make(Cache::class)))->release($job, $job->debounceOwner);
            });
        }
    }

    /**
     * Build entries, raise JobQueueing events, fire batches in parallel, then
     * raise JobQueued events using the MessageIds returned by SQS.
     *
     * @param  array<int, mixed>  $jobs
     * @param  mixed  $data
     */
    protected function dispatchBulk(array $jobs, $data, string $queue): void
    {
        $entries = $this->buildEntries($jobs, $data, $queue);

        foreach ($entries as $item) {
            $this->raiseJobQueueingEvent($queue, $item['job'], $item['payload'], $item['delay']);
        }

        $chunks = $this->chunkEntries($entries, $queue);
        $sqs = $this->getSqs();

        $requests = function () use ($chunks, $queue, $sqs) {
            foreach ($chunks as $chunk) {
                yield $sqs->sendMessageBatchAsync([
                    'QueueUrl' => $queue,
                    'Entries' => array_map(fn ($item) => $item['entry'], $chunk),
                ]);
            }
        };

        $failures = [];

        Each::ofLimitAll(
            $requests(),
            $this->maxConcurrentBatches,
            function ($response, int $idx) use ($chunks, $queue, &$failures) {
                $map = [];
                foreach ($chunks[$idx] as $item) {
                    $map[$item['entry']['Id']] = $item;
                }

                foreach ($response['Successful'] ?? [] as $success) {
                    if (! isset($map[$success['Id']])) {
                        continue;
                    }

                    $item = $map[$success['Id']];

                    $this->raiseJobQueuedEvent(
                        $queue,
                        $success['MessageId'],
                        $item['job'],
                        $item['payload'],
                        $item['delay'],
                    );
                }

                foreach ($response['Failed'] ?? [] as $failed) {
                    $failures[] = $failed;
                }
            },
        )->wait();

        if (! empty($failures)) {
            throw new RuntimeException(sprintf(
                'SQS SendMessageBatch reported %d failed entries: %s',
                count($failures),
                json_encode($failures),
            ));
        }
    }

    /**
     * Build SendMessageBatch entry rows for each job, retaining the job +
     * payload alongside each entry so events can be raised later. Payloads
     * that qualify for overflow storage are offloaded before batching.
     *
     * @param  array<int, mixed>  $jobs
     * @param  mixed  $data
     * @return array<int, array{job: mixed, payload: string, delay: mixed, entry: array<string, mixed>}>
     */
    protected function buildEntries(array $jobs, $data, string $queue): array
    {
        $entries = [];

        foreach ($jobs as $job) {
            $delay = is_object($job) ? ($job->delay ?? null) : null;

            $payload = $this->createPayload($job, $queue, $data, $delay);

            if ($this->willOverflow($payload)) {
                $payload = $this->overflow($payload);
            }

            $entry = [
                'Id' => Str::uuid()->toString(),
                'MessageBody' => $payload,
            ];

            $options = $this->getQueueableOptions($job, $queue, $payload, $delay);

            if (isset($options['DelaySeconds'])) {
                $entry['DelaySeconds'] = $options['DelaySeconds'];
            }

            if (isset($options['MessageGroupId'])) {
                $entry['MessageGroupId'] = $options['MessageGroupId'];
            }

            if (isset($options['MessageDeduplicationId'])) {
                $entry['MessageDeduplicationId'] = $options['MessageDeduplicationId'];
            }

            $entries[] = [
                'job' => $job,
                'payload' => $payload,
                'delay' => $delay,
                'entry' => $entry,
            ];
        }

        return $entries;
    }

    /**
     * Chunk entries to respect SQS limits: at most 10 entries per batch and a
     * serialized POST body of at most 1 MiB per batch.
     *
     * Each entry is measured as its JSON-serialized form — the bytes it
     * actually contributes to the POST body, including entry metadata and
     * string-escaping overhead — mirroring the AWS SDK's json-protocol
     * serialization. The request envelope around the entries is budgeted
     * up front so the whole POST stays within the limit.
     *
     * @param  array<int, array{job: mixed, payload: string, delay: mixed, entry: array<string, mixed>}>  $entries
     * @return array<int, array<int, array{job: mixed, payload: string, delay: mixed, entry: array<string, mixed>}>>
     */
    protected function chunkEntries(array $entries, string $queue): array
    {
        $available = self::MAX_POST_BYTES - strlen(json_encode(
            ['QueueUrl' => $queue, 'Entries' => []], JSON_THROW_ON_ERROR,
        ));

        $chunks = [];
        $current = [];
        $currentBytes = 0;

        foreach ($entries as $item) {
            $bytes = strlen(json_encode($item['entry'], JSON_THROW_ON_ERROR)) + 1;

            if ($bytes > $available) {
                throw new RuntimeException(sprintf(
                    'SQS batch entry of %d serialized bytes exceeds the %d byte request limit.',
                    $bytes,
                    self::MAX_POST_BYTES,
                ));
            }

            $wouldExceedCount = count($current) >= self::MAX_BATCH_COUNT;
            $wouldExceedBytes = $currentBytes + $bytes > $available;

            if (! empty($current) && ($wouldExceedCount || $wouldExceedBytes)) {
                $chunks[] = $current;
                $current = [];
                $currentBytes = 0;
            }

            $current[] = $item;
            $currentBytes += $bytes;
        }

        if (! empty($current)) {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
