<?php

namespace App\Queue\Connectors;

use App\Queue\BatchSqsQueue;
use Aws\Sqs\SqsClient;
use Illuminate\Queue\Connectors\SqsConnector;
use Illuminate\Support\Arr;

class BatchSqsConnector extends SqsConnector
{
    /**
     * @return \Illuminate\Contracts\Queue\Queue
     */
    public function connect(array $config)
    {
        $config = $this->getDefaultConfiguration($config);

        if ($credentials = $this->resolveCredentialProvider($config)) {
            $config["credentials"] = $credentials;
        } elseif (!empty($config["key"]) && !empty($config["secret"])) {
            $config["credentials"] = Arr::only($config, ["key", "secret"]);

            if (!empty($config["token"])) {
                $config["credentials"]["token"] = $config["token"];
            }
        }

        return new BatchSqsQueue(
            new SqsClient(Arr::except($config, ["token"])),
            $config["queue"],
            $config["prefix"] ?? "",
            $config["suffix"] ?? "",
            $config["after_commit"] ?? null,
        );
    }
}
