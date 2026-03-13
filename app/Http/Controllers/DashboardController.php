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
                'activeWorkers' => DB::table('worker_heartbeats')
                    ->where('last_seen_at', '>=', now()->subSeconds(10))
                    ->count(),
                'peakWorkers' => $batchId ? $this->calculatePeakConcurrencyFromDb($batchId) : 0,
                'uniqueWorkers' => (int) ($stats->unique_workers ?? 0),
                'jobsPerSecond' => $stats?->completed > 0 && $stats->max_completed_at && $stats->min_dispatched_at && ($stats->max_completed_at - $stats->min_dispatched_at) > 0
                    ? round($stats->completed / ($stats->max_completed_at - $stats->min_dispatched_at), 1)
                    : null,
            ],
            'jobs' => fn () => $batchId ? $this->getBatchJobs($batchId) : [],
            'recentBatches' => $this->getRecentBatches(),
        ]);
    }

    public function dispatch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:10000'],
            'min_duration' => ['required', 'integer', 'min:100', 'max:10000'],
            'max_duration' => ['required', 'integer', 'min:100', 'max:10000'],
            'queue' => ['required', 'string', 'in:default,processing,critical'],
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

        $jobs = $metricIds->map(function (int $id) use ($validated) {
            $duration = random_int($validated['min_duration'], $validated['max_duration']);

            return new DemoJob($id, $duration);
        })->all();

        // Uses BatchSqsQueue::bulk() which sends via sendMessageBatchAsync in parallel
        Queue::connection(config('queue.default'))->bulk($jobs, '', $validated['queue']);

        return redirect()->route('dashboard', ['batch' => $batchId]);
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
            ->orderBy('job_number')
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
