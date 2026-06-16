<?php

namespace App\Providers;

use App\Queue\Connectors\BatchSqsConnector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Cloud\QueueConnector;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        // DB::prohibitDestructiveCommands(
        //     app()->isProduction(),
        // );

        Queue::addConnector('sqs', fn () => new BatchSqsConnector);

        // On Laravel Cloud with managed queues, Cloud::bootManagedQueues() runs
        // after BootProviders and re-registers the 'sqs' connector to wrap a
        // vanilla SqsConnector inside its QueueConnector lifecycle wrapper —
        // overriding the BatchSqsConnector we registered above. Extending the
        // QueueConnector container binding lets us swap the inner connector
        // back to BatchSqsConnector while preserving Cloud's job telemetry.
        if (($_SERVER['LARAVEL_CLOUD_MANAGED_QUEUES'] ?? null) === '1') {
            $this->app->extend(
                QueueConnector::class,
                fn ($_, $app) => new QueueConnector(new BatchSqsConnector, $app),
            );
        }

        $this->registerWorkerHeartbeat();
    }

    private function registerWorkerHeartbeat(): void
    {
        $lastHeartbeatAt = 0.0;

        Event::listen(Looping::class, function (Looping $event) use (
            &$lastHeartbeatAt,
        ): void {
            $now = microtime(true);

            if ($now - $lastHeartbeatAt < 3) {
                return;
            }

            $lastHeartbeatAt = $now;

            $workerId = gethostname().':'.getmypid();

            DB::table('workers')->updateOrInsert(
                ['worker_id' => $workerId],
                ['queue' => $event->queue, 'started_at' => now()],
            );
        });

        Event::listen(WorkerStopping::class, function (): void {
            $workerId = gethostname().':'.getmypid();

            DB::table('workers')->where('worker_id', $workerId)->delete();
        });
    }
}
