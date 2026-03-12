<?php

namespace App\Http\Controllers;

use App\Jobs\DemoJob;
use App\Models\JobMetric;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController
{
    public function index(Request $request): Response
    {
        $batchId = $request->query('batch');

        $metrics = $batchId
            ? JobMetric::where('batch_id', $batchId)->orderBy('job_number')->get()
            : collect();

        $completed = $metrics->whereNotNull('completed_at');
        $pending = $metrics->whereNull('picked_up_at');
        $processing = $metrics->whereNotNull('picked_up_at')->whereNull('completed_at');

        $batchDispatchedAt = $metrics->min('dispatched_at');

        return Inertia::render('Dashboard', [
            'batchId' => $batchId,
            'stats' => [
                'total' => $metrics->count(),
                'pending' => $pending->count(),
                'processing' => $processing->count(),
                'completed' => $completed->count(),
                'avgWaitMs' => $completed->count() > 0
                    ? round($completed->avg(fn (JobMetric $m) => ($m->picked_up_at - $m->dispatched_at) * 1000))
                    : null,
                'avgProcessMs' => $completed->count() > 0
                    ? round($completed->avg(fn (JobMetric $m) => ($m->completed_at - $m->picked_up_at) * 1000))
                    : null,
                'totalDurationMs' => $completed->count() > 0
                    ? round(($completed->max('completed_at') - $batchDispatchedAt) * 1000)
                    : null,
                'activeWorkers' => $processing->pluck('worker_id')->filter()->unique()->count(),
                'peakWorkers' => $this->calculatePeakConcurrency($metrics),
                'uniqueWorkers' => $metrics->pluck('worker_id')->filter()->unique()->count(),
                'jobsPerSecond' => $completed->count() > 0 && ($completed->max('completed_at') - $batchDispatchedAt) > 0
                    ? round($completed->count() / ($completed->max('completed_at') - $batchDispatchedAt), 1)
                    : null,
            ],
            'jobs' => $metrics->map(fn (JobMetric $m) => [
                'id' => $m->id,
                'number' => $m->job_number,
                'queue' => $m->queue,
                'worker' => $m->worker_id,
                'waitMs' => $m->picked_up_at ? round(($m->picked_up_at - $batchDispatchedAt) * 1000) : null,
                'startMs' => $m->picked_up_at ? round(($m->picked_up_at - $batchDispatchedAt) * 1000) : null,
                'endMs' => $m->completed_at ? round(($m->completed_at - $batchDispatchedAt) * 1000) : null,
                'status' => $m->completed_at ? 'completed' : ($m->picked_up_at ? 'processing' : 'pending'),
            ])->values(),
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
        $jobs = [];

        for ($i = 1; $i <= $validated['count']; $i++) {
            $metric = JobMetric::create([
                'batch_id' => $batchId,
                'queue' => $validated['queue'],
                'job_number' => $i,
                'dispatched_at' => $now,
            ]);

            $duration = random_int($validated['min_duration'], $validated['max_duration']);
            $jobs[] = (new DemoJob($metric->id, $duration))->onQueue($validated['queue']);
        }

        foreach ($jobs as $job) {
            dispatch($job);
        }

        return redirect()->route('dashboard', ['batch' => $batchId]);
    }

    /**
     * Calculate peak concurrent workers using a sweep line algorithm.
     *
     * @param  Collection<int, JobMetric>  $metrics
     */
    private function calculatePeakConcurrency(Collection $metrics): int
    {
        $events = [];

        foreach ($metrics as $metric) {
            if ($metric->picked_up_at === null) {
                continue;
            }

            $events[] = ['time' => $metric->picked_up_at, 'type' => 1];
            $events[] = ['time' => $metric->completed_at ?? PHP_FLOAT_MAX, 'type' => -1];
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
     * @return Collection<int, array{id: string, jobCount: int, peakWorkers: int, dispatchedAt: string}>
     */
    private function getRecentBatches(): Collection
    {
        $batchIds = JobMetric::query()
            ->selectRaw('batch_id, count(*) as job_count, min(dispatched_at) as dispatched_at')
            ->groupBy('batch_id')
            ->orderByDesc('dispatched_at')
            ->limit(10)
            ->get();

        $allMetrics = JobMetric::query()
            ->whereIn('batch_id', $batchIds->pluck('batch_id'))
            ->whereNotNull('picked_up_at')
            ->get()
            ->groupBy('batch_id');

        return $batchIds->map(fn ($b) => [
            'id' => $b->batch_id,
            'jobCount' => $b->job_count,
            'peakWorkers' => $this->calculatePeakConcurrency($allMetrics->get($b->batch_id, collect())),
            'dispatchedAt' => date('H:i:s', (int) $b->dispatched_at),
        ]);
    }
}
