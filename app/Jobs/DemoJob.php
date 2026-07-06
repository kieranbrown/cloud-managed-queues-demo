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

    /**
     * Memory blocks retained for the lifetime of the worker process to
     * simulate a leak. Static so it survives past handle() — the worker checks
     * memory_get_usage(true) *between* jobs, so anything freed when handle()
     * returns is already gone before `queue:work --memory` can observe it.
     *
     * @var list<string>
     */
    private static array $retained = [];

    public function __construct(
        public int $metricId,
        public int $workDurationMs = 500,
        // Opaque padding to inflate the serialized SQS message body for
        // poller memory testing. Must be a constructor property so it is
        // serialized into the message (a body generated in handle() would not
        // travel through SQS or the poller). Defaults to empty — no overhead.
        public string $payload = '',
        // Force the worker to allocate this many bytes at runtime, to test
        // worker memory pressure. Unlike $payload this is allocated in handle()
        // and never travels through SQS. 0 = no forced allocation.
        public int $memoryBytes = 0,
        // Retain the allocated memory for the worker's lifetime instead of
        // releasing it when the job ends. Required to trip `queue:work --memory`
        // (exit code 12): the guard reads memory_get_usage(true) *after* each
        // job, and a released block is already freed by then (large PHP
        // allocations return to the OS immediately). Retaining accumulates
        // memory across jobs, simulating a leak.
        public bool $retainMemory = false,
    ) {}

    public function handle(): void
    {
        $workerId = gethostname().':'.getmypid();

        JobMetric::where('id', $this->metricId)->update([
            'picked_up_at' => microtime(true),
            'worker_id' => $workerId,
        ]);

        // Held in a local so it stays referenced (and counts toward memory) for
        // the whole job; released when handle() returns unless we also retain it.
        $block = $this->memoryBytes > 0 ? str_repeat('x', $this->memoryBytes) : null;

        if ($block !== null && $this->retainMemory) {
            self::$retained[] = $block;
        }

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
