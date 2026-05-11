<?php

namespace App\Queue;

use GuzzleHttp\Promise\Each;
use Illuminate\Queue\SqsQueue;
use Illuminate\Support\Str;

class BatchSqsQueue extends SqsQueue
{
    /**
     * Maximum number of SendMessageBatch requests in flight at once.
     */
    private const MAX_CONCURRENT_BATCHES = 50;

    /**
     * Push an array of jobs onto the queue using SQS SendMessageBatch API.
     *
     * Sends up to 10 messages per batch request (SQS limit), with batch
     * requests fired in parallel up to MAX_CONCURRENT_BATCHES at a time.
     *
     * @param  array  $jobs
     * @param  mixed  $data
     * @param  string|null  $queue
     */
    public function bulk($jobs, $data = '', $queue = null): void
    {
        $queue = $this->getQueue($queue);

        $entries = [];

        foreach ((array) $jobs as $job) {
            $payload = $this->createPayload($job, $queue, $data);

            $entry = [
                'Id' => Str::uuid()->toString(),
                'MessageBody' => $payload,
            ];

            $options = $this->getQueueableOptions($job, $queue, $payload);

            if (isset($options['DelaySeconds'])) {
                $entry['DelaySeconds'] = $options['DelaySeconds'];
            }

            if (isset($options['MessageGroupId'])) {
                $entry['MessageGroupId'] = $options['MessageGroupId'];
            }

            if (isset($options['MessageDeduplicationId'])) {
                $entry['MessageDeduplicationId'] = $options['MessageDeduplicationId'];
            }

            $entries[] = $entry;
        }

        $sqs = $this->getSqs();

        $requests = function () use ($entries, $queue, $sqs) {
            foreach (array_chunk($entries, 10) as $chunk) {
                yield $sqs->sendMessageBatchAsync([
                    'QueueUrl' => $queue,
                    'Entries' => $chunk,
                ]);
            }
        };

        Each::ofLimitAll($requests(), self::MAX_CONCURRENT_BATCHES)->wait();
    }
}
