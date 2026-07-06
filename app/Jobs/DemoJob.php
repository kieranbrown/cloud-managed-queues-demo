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

    public int $timeout = 0;

    private ?float $deadline = null;

    public function __construct(
        public int $metricId,
        public int $workDurationMs = 500,
        // Opaque padding to inflate the serialized SQS message body for
        // poller memory testing. Must be a constructor property so it is
        // serialized into the message (a body generated in handle() would not
        // travel through SQS or the poller). Defaults to empty — no overhead.
        public string $payload = '',
        // Force the worker to hold this many bytes of live memory while the job
        // runs, to test worker memory pressure. Unlike $payload this is
        // allocated at runtime in handle() and never travels through SQS.
        // 0 = no forced allocation.
        public int $memoryBytes = 0,
    ) {}

    public function handle(): void
    {
        $workerId = gethostname().':'.getmypid();

        JobMetric::where('id', $this->metricId)->update([
            'picked_up_at' => microtime(true),
            'worker_id' => $workerId,
        ]);

        // Allocate the requested memory and keep it referenced for the whole
        // job so peak worker memory reflects the forced amount, then release.
        $hog = $this->memoryBytes > 0 ? str_repeat('x', $this->memoryBytes) : null;

        $this->deadline = microtime(true) + ($this->workDurationMs / 1000);

        $this->sleepUntilDeadline();

        JobMetric::where('id', $this->metricId)->update([
            'completed_at' => microtime(true),
        ]);

        unset($hog);
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
