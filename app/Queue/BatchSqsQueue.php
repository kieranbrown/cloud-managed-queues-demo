<?php

namespace App\Queue;

use GuzzleHttp\Promise\Utils;
use Illuminate\Queue\SqsQueue;
use Illuminate\Support\Str;

class BatchSqsQueue extends SqsQueue
{
    /**
     * Push an array of jobs onto the queue using SQS SendMessageBatch API.
     *
     * Sends up to 10 messages per batch request (SQS limit), with all
     * batch requests fired in parallel using async promises.
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

        $promises = [];

        foreach (array_chunk($entries, 10) as $chunk) {
            $promises[] = $this->getSqs()->sendMessageBatchAsync([
                'QueueUrl' => $queue,
                'Entries' => $chunk,
            ]);
        }

        Utils::all($promises)->wait();
    }
}
