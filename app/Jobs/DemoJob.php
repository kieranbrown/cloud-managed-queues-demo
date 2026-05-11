<?php

namespace App\Jobs;

use App\Models\JobMetric;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DemoJob implements ShouldQueue
{
    use Queueable;

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

        usleep($this->workDurationMs * 1000);

        JobMetric::where('id', $this->metricId)->update([
            'completed_at' => microtime(true),
        ]);
    }
}
