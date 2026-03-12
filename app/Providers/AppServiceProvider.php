<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

        $this->registerWorkerHeartbeat();
    }

    private function registerWorkerHeartbeat(): void
    {
        $lastHeartbeat = 0.0;

        Event::listen(Looping::class, function (Looping $event) use (&$lastHeartbeat): void {
            $now = microtime(true);

            if ($now - $lastHeartbeat < 3) {
                return;
            }

            $lastHeartbeat = $now;
            $workerId = gethostname().':'.getmypid();

            DB::table('worker_heartbeats')->updateOrInsert(
                ['worker_id' => $workerId],
                ['queue' => $event->queue, 'last_seen_at' => now()],
            );
        });
    }
}
