<?php

namespace App\Jobs;

use App\Models\JobMetric;
use Illuminate\Contracts\Queue\Interruptible;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DemoJob implements Interruptible, ShouldQueue
{
    use Queueable;

    private ?float $deadline = null;

    public function __construct(
        public int $metricId,
        public int $workDurationMs = 500,
    ) {}

    public function handle(): void
    {
        $workerId = gethostname().':'.getmypid();

        JobMetric::where('id', $this->metricId)->update([
            'picked_up_at' => microtime(true),
            'worker_id' => $workerId,
        ]);

        $this->deadline = microtime(true) + ($this->workDurationMs / 1000);

        $this->sleepUntilDeadline();

        JobMetric::where('id', $this->metricId)->update([
            'completed_at' => microtime(true),
        ]);
    }

    public function interrupted(int $signal): void
    {
        $remainingMs = $this->deadline === null
            ? null
            : max(0, ($this->deadline - microtime(true)) * 1000);

        Log::warning('DemoJob received worker signal', [
            'metric_id' => $this->metricId,
            'signal' => $signal,
            'signal_name' => $this->signalName($signal),
            'received_at' => now()->toDateTimeString('millisecond'),
            'remaining_ms' => $remainingMs,
        ]);
    }

    private function sleepUntilDeadline(): void
    {
        while (($remaining = $this->deadline - microtime(true)) > 0) {
            usleep((int) ($remaining * 1_000_000));
        }
    }

    private function signalName(int $signal): string
    {
        return match ($signal) {
            SIGTERM => 'SIGTERM',
            SIGINT => 'SIGINT',
            SIGQUIT => 'SIGQUIT',
            SIGUSR2 => 'SIGUSR2',
            default => 'UNKNOWN',
        };
    }
}
