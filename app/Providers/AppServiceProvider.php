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

        $this->registerWorkerHeartbeat();
        $this->logJobMemoryUsage();
    }

    /**
     * Print the worker's real memory usage after each job — this is exactly the
     * figure `queue:work --memory` checks (memory_get_usage(true)) between jobs,
     * so it shows why the guard does or doesn't trip. Console only.
     */
    private function logJobMemoryUsage(): void
    {
        if (! $this->app->runningInConsole() || ! defined('STDERR')) {
            return;
        }

        Event::listen(JobProcessed::class, function (JobProcessed $event): void {
            fwrite(STDERR, sprintf(
                "[memory] after %s: %s MB real (peak %s MB) — --memory checks the real figure\n",
                class_basename($event->job->resolveName()),
                round(memory_get_usage(true) / 1048576, 1),
                round(memory_get_peak_usage(true) / 1048576, 1),
            ));
        });
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
     * A standard worker registers itself once when it boots and deregisters
     * when it stops, so it appears in the table for its whole lifetime without
     * any periodic writes.
     */
    private function registerStandardWorkerHeartbeat(string $workerId): void
    {
        $registered = false;

        Event::listen(Looping::class, function (Looping $event) use (
            $workerId,
            &$registered,
        ): void {
            if ($registered) {
                return;
            }

            $registered = true;

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
