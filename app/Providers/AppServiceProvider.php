<?php

namespace App\Providers;

use App\Queue\Connectors\BatchSqsConnector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Cloud\QueueConnector;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
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
        $workerId = gethostname().':'.getmypid();

        if ($this->isHibernatingWorker()) {
            $this->registerHibernatingWorkerHeartbeat($workerId);

            return;
        }

        $this->registerStandardWorkerHeartbeat($workerId);
    }

    /**
     * Zeropod workers hibernate when idle, so their keepalive interval is
     * injected into the process environment at runtime. It is not baked into
     * the cached config, so the env() helper can't see it — read the raw
     * superglobals instead.
     */
    private function isHibernatingWorker(): bool
    {
        return isset($_ENV['ZEROPOD_KEEPALIVE_INTERVAL'])
            || isset($_SERVER['ZEROPOD_KEEPALIVE_INTERVAL']);
    }

    /**
     * A standard worker stays registered for its whole lifetime, refreshing
     * periodically so that resetting the workers table is self-healing.
     */
    private function registerStandardWorkerHeartbeat(string $workerId): void
    {
        $lastHeartbeatAt = 0.0;

        Event::listen(Looping::class, function (Looping $event) use (
            $workerId,
            &$lastHeartbeatAt,
        ): void {
            $now = microtime(true);

            if ($now - $lastHeartbeatAt < 3) {
                return;
            }

            $lastHeartbeatAt = $now;

            DB::table('workers')->updateOrInsert(
                ['worker_id' => $workerId],
                ['queue' => $event->queue, 'started_at' => now()],
            );
        });

        Event::listen(WorkerStopping::class, function () use ($workerId): void {
            DB::table('workers')->where('worker_id', $workerId)->delete();
        });
    }

    /**
     * A hibernating worker spins down to nothing when idle, so it should only
     * count as a worker while it is actively processing a job: register when a
     * job starts and deregister the moment it finishes, fails, or stops.
     */
    private function registerHibernatingWorkerHeartbeat(string $workerId): void
    {
        $queue = 'default';

        Event::listen(Looping::class, function (Looping $event) use (&$queue): void {
            $queue = $event->queue;
        });

        Event::listen(JobProcessing::class, function () use ($workerId, &$queue): void {
            DB::table('workers')->updateOrInsert(
                ['worker_id' => $workerId],
                ['queue' => $queue, 'started_at' => now()],
            );
        });

        $deregister = function () use ($workerId): void {
            DB::table('workers')->where('worker_id', $workerId)->delete();
        };

        Event::listen(JobProcessed::class, $deregister);
        Event::listen(JobFailed::class, $deregister);
        Event::listen(WorkerStopping::class, $deregister);
    }
}
