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

        Event::listen(Looping::class, function (Looping $event) use (&$registered): void {
            if ($registered) {
                return;
            }

            $workerId = gethostname().':'.getmypid();

            DB::table('workers')->updateOrInsert(
                ['worker_id' => $workerId],
                ['queue' => $event->queue, 'started_at' => now()],
            );

            $registered = true;
        });

        Event::listen(WorkerStopping::class, function (): void {
            $workerId = gethostname().':'.getmypid();

            DB::table('workers')
                ->where('worker_id', $workerId)
                ->delete();
        });
    }
}
