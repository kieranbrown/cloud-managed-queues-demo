<?php

namespace App\Http\Controllers;

use App\Jobs\DemoJob;
use App\Models\JobMetric;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController
{
    public function index(Request $request): Response
    {
        $batchId = $request->query('batch');

        $stats = $batchId ? $this->getBatchStats($batchId) : null;

        return Inertia::render('Dashboard', [
            'batchId' => $batchId,
            'stats' => [
                'total' => $stats->total ?? 0,
                'pending' => $stats->pending ?? 0,
                'processing' => $stats->processing ?? 0,
                'completed' => $stats->completed ?? 0,
                'avgWaitMs' => $stats?->avg_wait_ms ? round($stats->avg_wait_ms) : null,
                'avgProcessMs' => $stats?->avg_process_ms ? round($stats->avg_process_ms) : null,
                'totalDurationMs' => $stats?->completed > 0 && $stats->max_completed_at && $stats->min_dispatched_at
                    ? round(($stats->max_completed_at - $stats->min_dispatched_at) * 1000)
                    : null,
                'activeWorkers' => DB::table('workers')->count(),
                'workersByQueue' => $this->getWorkersByQueue(),
                'peakWorkers' => $batchId ? $this->calculatePeakConcurrencyFromDb($batchId) : 0,
                'uniqueWorkers' => (int) ($stats->unique_workers ?? 0),
                'jobsPerSecond' => $stats?->completed > 0 && $stats->max_completed_at && $stats->min_dispatched_at && ($stats->max_completed_at - $stats->min_dispatched_at) > 0
                    ? round($stats->completed / ($stats->max_completed_at - $stats->min_dispatched_at), 1)
                    : null,
            ],
            'jobs' => $batchId ? $this->getBatchJobs($batchId) : [],
            'recentBatches' => $this->getRecentBatches(),
        ]);
    }

    public function dispatch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:10000'],
            'min_duration' => ['required', 'integer', 'min:0'],
            'max_duration' => ['required', 'integer', 'min:0'],
            'queue' => ['required', 'string', 'in:default,processing,critical'],
            // Optional per-job payload padding (bytes) for poller memory tests.
            // Laravel's job envelope adds a fixed ~740 B of overhead (measured),
            // and SQS's hard limit is 1,048,576 B (1 MiB), so 1,045,000 leaves a
            // ~2.8 KB safety margin. Default 0 = no payload.
            'payload_bytes' => ['sometimes', 'integer', 'min:0', 'max:1045000'],
            // Optional per-job runtime memory allocation (bytes) for worker
            // memory-pressure tests. Uncapped by design — forcing a job past the
            // worker's memory_limit is a valid thing to test. Default 0 = none.
            'memory_bytes' => ['sometimes', 'integer', 'min:0'],
            // Retain that memory across jobs (simulated leak) so it survives the
            // worker's between-jobs memory check and can trip `queue:work --memory`.
            'retain_memory' => ['sometimes', 'boolean'],
        ]);

        $batchId = Str::ulid()->toString();
        $now = microtime(true);
        $count = $validated['count'];

        $metricRows = [];

        for ($i = 1; $i <= $count; $i++) {
            $metricRows[] = [
                'batch_id' => $batchId,
                'queue' => $validated['queue'],
                'job_number' => $i,
                'dispatched_at' => $now,
            ];
        }

        // Bulk insert metrics in chunks of 500 (SQLite/MySQL parameter limits)
        foreach (array_chunk($metricRows, 500) as $chunk) {
            JobMetric::insert($chunk);
        }

        // Fetch inserted IDs to pair with jobs
        $metricIds = JobMetric::where('batch_id', $batchId)
            ->orderBy('job_number')
            ->pluck('id');

        // Build the padding once and share it across every job in the batch:
        // each job still serializes its own copy into its own SQS message, but
        // we avoid generating thousands of ~1 MiB strings in the dispatcher.
        $payloadBytes = (int) ($validated['payload_bytes'] ?? 0);
        $payload = $payloadBytes > 0 ? Str::random($payloadBytes) : '';

        $memoryBytes = (int) ($validated['memory_bytes'] ?? 0);
        $retainMemory = (bool) ($validated['retain_memory'] ?? false);

        $jobs = $metricIds->map(function (int $id) use ($validated, $payload, $memoryBytes, $retainMemory) {
            $duration = random_int($validated['min_duration'], $validated['max_duration']);

            return new DemoJob($id, $duration, $payload, $memoryBytes, $retainMemory);
        })->all();

        // Uses BatchSqsQueue::bulk() which sends via sendMessageBatchAsync in parallel
        Queue::connection(config('queue.default'))->bulk($jobs, '', $validated['queue']);

        return redirect()->route('dashboard', ['batch' => $batchId]);
    }

    /**
     * Count active workers grouped by the queue they are processing.
     *
     * @return array<string, int>
     */
    private function getWorkersByQueue(): array
    {
        $counts = DB::table('workers')
            ->selectRaw('queue, count(*) as count')
            ->groupBy('queue')
            ->pluck('count', 'queue');

        $byQueue = ['default' => 0, 'processing' => 0, 'critical' => 0];

        foreach ($counts as $queue => $count) {
            foreach (explode(',', (string) ($queue ?? 'default')) as $name) {
                $name = trim($name) ?: 'default';

                if (array_key_exists($name, $byQueue)) {
                    $byQueue[$name] += (int) $count;
                }
            }
        }

        return $byQueue;
    }

    /**
     * Clear the active workers table. Useful when workers get stuck and
     * the count no longer reflects reality.
     */
    public function resetWorkers(): RedirectResponse
    {
        DB::table('workers')->delete();

        return back();
    }

    /**
     * Wipe all batch, job, and queue data, leaving worker registrations intact.
     */
    public function reset(): RedirectResponse
    {
        foreach (['job_metrics', 'jobs', 'job_batches', 'failed_jobs'] as $table) {
            DB::table($table)->delete();
        }

        return redirect()->route('dashboard');
    }

    private function getBatchStats(string $batchId): object
    {
        return DB::table('job_metrics')
            ->where('batch_id', $batchId)
            ->selectRaw('count(*) as total')
            ->selectRaw('count(case when picked_up_at is null then 1 end) as pending')
            ->selectRaw('count(case when picked_up_at is not null and completed_at is null then 1 end) as processing')
            ->selectRaw('count(case when completed_at is not null then 1 end) as completed')
            ->selectRaw('avg(case when completed_at is not null then (picked_up_at - dispatched_at) * 1000 end) as avg_wait_ms')
            ->selectRaw('avg(case when completed_at is not null then (completed_at - picked_up_at) * 1000 end) as avg_process_ms')
            ->selectRaw('min(dispatched_at) as min_dispatched_at')
            ->selectRaw('max(completed_at) as max_completed_at')
            ->selectRaw('count(distinct worker_id) as unique_workers')
            ->first();
    }

    /**
     * @return array<int, array{id: int, number: int, queue: string, worker: string|null, waitMs: float|null, startMs: float|null, endMs: float|null, status: string}>
     */
    private function getBatchJobs(string $batchId): array
    {
        $batchDispatchedAt = DB::table('job_metrics')
            ->where('batch_id', $batchId)
            ->min('dispatched_at');

        return DB::table('job_metrics')
            ->where('batch_id', $batchId)
            ->orderByRaw('picked_up_at is null, picked_up_at asc, job_number asc')
            ->get(['id', 'job_number', 'queue', 'worker_id', 'dispatched_at', 'picked_up_at', 'completed_at'])
            ->map(fn (object $m) => [
                'id' => $m->id,
                'number' => $m->job_number,
                'queue' => $m->queue,
                'worker' => $m->worker_id,
                'waitMs' => $m->picked_up_at ? round(($m->picked_up_at - $batchDispatchedAt) * 1000) : null,
                'startMs' => $m->picked_up_at ? round(($m->picked_up_at - $batchDispatchedAt) * 1000) : null,
                'endMs' => $m->completed_at ? round(($m->completed_at - $batchDispatchedAt) * 1000) : null,
                'status' => $m->completed_at ? 'completed' : ($m->picked_up_at ? 'processing' : 'pending'),
            ])->values()->all();
    }

    /**
     * Calculate peak concurrent workers using a sweep line algorithm.
     * Only fetches the two columns needed instead of full row hydration.
     */
    private function calculatePeakConcurrencyFromDb(string $batchId): int
    {
        $rows = DB::table('job_metrics')
            ->where('batch_id', $batchId)
            ->whereNotNull('picked_up_at')
            ->get(['picked_up_at', 'completed_at']);

        $events = [];

        foreach ($rows as $row) {
            $events[] = ['time' => $row->picked_up_at, 'type' => 1];
            $events[] = ['time' => $row->completed_at ?? PHP_FLOAT_MAX, 'type' => -1];
        }

        usort($events, fn ($a, $b) => $a['time'] <=> $b['time'] ?: $a['type'] <=> $b['type']);

        $peak = 0;
        $current = 0;

        foreach ($events as $event) {
            $current += $event['type'];
            $peak = max($peak, $current);
        }

        return $peak;
    }

    /**
     * @return Collection<int, array{id: string, jobCount: int, uniqueWorkers: int, dispatchedAt: string}>
     */
    private function getRecentBatches(): Collection
    {
        $batchIds = JobMetric::query()
            ->selectRaw('batch_id, count(*) as job_count, count(distinct worker_id) as unique_workers, min(dispatched_at) as dispatched_at')
            ->groupBy('batch_id')
            ->orderByDesc('dispatched_at')
            ->limit(10)
            ->get();

        return $batchIds->map(fn ($b) => [
            'id' => $b->batch_id,
            'jobCount' => $b->job_count,
            'uniqueWorkers' => (int) $b->unique_workers,
            'dispatchedAt' => date('H:i:s', (int) $b->dispatched_at),
        ]);
    }
}
