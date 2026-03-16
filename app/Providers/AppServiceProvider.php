<?php

namespace App\Providers;

use App\Queue\Connectors\BatchSqsConnector;
use Carbon\CarbonImmutable;
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

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Queue::addConnector('batch-sqs', fn () => new BatchSqsConnector);

        $this->registerWorkerHeartbeat();
    }

    private function registerWorkerHeartbeat(): void
    {
        $registered = false;
        $lastHeartbeat = 0.0;

        Event::listen(Looping::class, function (Looping $event) use (&$registered, &$lastHeartbeat): void {
            $workerId = gethostname().':'.getmypid();

            if (! $registered) {
                DB::table('worker_heartbeats')->updateOrInsert(
                    ['worker_id' => $workerId],
                    ['queue' => $event->queue, 'active' => true, 'last_seen_at' => now()],
                );

                $registered = true;
                $lastHeartbeat = microtime(true);

                return;
            }

            $now = microtime(true);

            if ($now - $lastHeartbeat < 3) {
                return;
            }

            $lastHeartbeat = $now;

            DB::table('worker_heartbeats')
                ->where('worker_id', $workerId)
                ->update(['last_seen_at' => now()]);
        });

        Event::listen(WorkerStopping::class, function (): void {
            $workerId = gethostname().':'.getmypid();

            DB::table('worker_heartbeats')
                ->where('worker_id', $workerId)
                ->update(['active' => false]);
        });
    }
}
