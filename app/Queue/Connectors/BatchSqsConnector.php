<?php

namespace App\Queue\Connectors;

use App\Queue\BatchSqsQueue;
use Aws\Handler\Guzzle\GuzzleHandler;
use Aws\Sqs\SqsClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Queue\Connectors\SqsConnector;
use Illuminate\Support\Arr;

class BatchSqsConnector extends SqsConnector
{
    public function connect(array $config)
    {
        $config = $this->getDefaultConfiguration($config);

        if (! empty($config['key']) && ! empty($config['secret'])) {
            $config['credentials'] = Arr::only($config, ['key', 'secret']);

            if (! empty($config['token'])) {
                $config['credentials']['token'] = $config['token'];
            }
        }

        $stack = HandlerStack::create();

        $stack->push(Middleware::retry(
            function (int $retries, Request $request, ?Response $response = null, ?\Throwable $exception = null): bool {
                return $retries < 3 && $exception instanceof ConnectException;
            },
            function (int $retries): int {
                return $retries * 100;
            },
        ));

        $client = new SqsClient(
            Arr::except($config, ['token']) + [
                'http_handler' => new GuzzleHandler(new Client(['handler' => $stack])),
            ],
        );

        return new BatchSqsQueue(
            $client,
            $config['queue'],
            $config['prefix'] ?? '',
            $config['suffix'] ?? '',
            $config['after_commit'] ?? null,
        );
    }
}
