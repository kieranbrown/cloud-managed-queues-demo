<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

interface Job {
    id: number;
    number: number;
    queue: string;
    worker: string | null;
    waitMs: number | null;
    startMs: number | null;
    endMs: number | null;
    status: 'pending' | 'processing' | 'completed';
}

interface Stats {
    total: number;
    pending: number;
    processing: number;
    completed: number;
    avgWaitMs: number | null;
    avgProcessMs: number | null;
    totalDurationMs: number | null;
    activeWorkers: number;
    peakWorkers: number;
    uniqueWorkers: number;
    jobsPerSecond: number | null;
}

interface Batch {
    id: string;
    jobCount: number;
    uniqueWorkers: number;
    dispatchedAt: string;
}

const props = defineProps<{
    batchId: string | null;
    stats: Stats;
    jobs: Job[];
    recentBatches: Batch[];
}>();

const form = useForm({
    count: 100,
    min_duration: 200,
    max_duration: 2000,
    queue: 'default',
});

const dispatching = ref(false);
let pollInterval: ReturnType<typeof setInterval> | null = null;

const isActive = computed(() => props.batchId && props.stats.total > 0 && props.stats.completed < props.stats.total);
const progress = computed(() => (props.stats.total > 0 ? (props.stats.completed / props.stats.total) * 100 : 0));

const timelineMax = computed(() => {
    if (props.jobs.length === 0) return 1000;
    const maxEnd = Math.max(...props.jobs.filter((j) => j.endMs).map((j) => j.endMs!), 0);
    const maxWait = Math.max(...props.jobs.filter((j) => j.waitMs).map((j) => j.waitMs!), 0);
    return Math.max(maxEnd, maxWait, 1000);
});

const workerColors = computed(() => {
    const workers = [...new Set(props.jobs.map((j) => j.worker).filter(Boolean))] as string[];
    const palette = [
        '#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981',
        '#06b6d4', '#f97316', '#6366f1', '#14b8a6', '#e11d48',
        '#84cc16', '#a855f7', '#0ea5e9', '#d946ef', '#22c55e',
    ];
    const map: Record<string, string> = {};
    workers.forEach((w, i) => (map[w] = palette[i % palette.length]));
    return map;
});

function submit(): void {
    dispatching.value = true;
    stopPolling();
    form.post('/dispatch', {
        onFinish: () => {
            dispatching.value = false;
            startPolling();
        },
    });
}

function startPolling(): void {
    stopPolling();
    pollInterval = setInterval(() => {
        router.reload({ only: props.batchId ? ['stats', 'jobs'] : ['stats'] });
    }, 500);
}

function stopPolling(): void {
    if (pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }
}

function selectBatch(id: string): void {
    router.get('/', { batch: id }, { preserveScroll: true });
}

function formatMs(ms: number | null): string {
    if (ms === null) return '-';
    if (ms < 1000) return `${Math.round(ms)}ms`;
    return `${(ms / 1000).toFixed(2)}s`;
}

onMounted(() => startPolling());
onUnmounted(() => stopPolling());
</script>

<template>
    <Head title="Dashboard" />

    <div class="min-h-screen bg-zinc-950 p-4 md:p-8">
        <div class="mx-auto max-w-7xl space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="font-mono text-2xl font-bold tracking-tight text-white">
                        Queue Clusters
                        <span class="text-zinc-500">Demo</span>
                    </h1>
                    <p class="mt-1 text-sm text-zinc-500">
                        Dispatch jobs and watch Laravel Cloud scale workers to meet demand
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-medium text-zinc-500">Workers</span>
                        <span class="font-mono text-lg font-bold text-violet-400">{{ stats.activeWorkers }}</span>
                    </div>
                    <div v-if="isActive" class="flex items-center gap-2">
                        <span class="relative flex h-3 w-3">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                            <span class="relative inline-flex h-3 w-3 rounded-full bg-emerald-500" />
                        </span>
                        <span class="text-sm font-medium text-emerald-400">Processing</span>
                    </div>
                </div>
            </div>

            <!-- Controls -->
            <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-6">
                <form class="flex flex-wrap items-end gap-4" @submit.prevent="submit">
                    <div class="min-w-[120px] flex-1">
                        <label class="mb-1.5 block text-xs font-medium text-zinc-400">Jobs</label>
                        <input
                            v-model.number="form.count"
                            type="number"
                            min="1"
                            max="10000"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 font-mono text-sm text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none"
                        />
                    </div>
                    <div class="min-w-[120px] flex-1">
                        <label class="mb-1.5 block text-xs font-medium text-zinc-400">Min Duration (ms)</label>
                        <input
                            v-model.number="form.min_duration"
                            type="number"
                            min="100"
                            max="10000"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 font-mono text-sm text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none"
                        />
                    </div>
                    <div class="min-w-[120px] flex-1">
                        <label class="mb-1.5 block text-xs font-medium text-zinc-400">Max Duration (ms)</label>
                        <input
                            v-model.number="form.max_duration"
                            type="number"
                            min="100"
                            max="10000"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 font-mono text-sm text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none"
                        />
                    </div>
                    <div class="min-w-[120px] flex-1">
                        <label class="mb-1.5 block text-xs font-medium text-zinc-400">Queue</label>
                        <select
                            v-model="form.queue"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 font-mono text-sm text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none"
                        >
                            <option value="default">default</option>
                            <option value="processing">processing</option>
                            <option value="critical">critical</option>
                        </select>
                    </div>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-lg bg-blue-600 px-6 py-2 font-mono text-sm font-semibold text-white transition hover:bg-blue-500 disabled:opacity-50"
                    >
                        {{ form.processing ? 'Dispatching...' : 'Dispatch' }}
                    </button>
                </form>
            </div>

            <!-- Stats Grid -->
            <div v-if="stats.total > 0" class="grid grid-cols-2 gap-3 md:grid-cols-5">
                <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4">
                    <div class="text-xs font-medium text-zinc-500">Total Jobs</div>
                    <div class="mt-1 font-mono text-2xl font-bold text-white">{{ stats.total }}</div>
                </div>
                <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4">
                    <div class="text-xs font-medium text-zinc-500">Completed</div>
                    <div class="mt-1 font-mono text-2xl font-bold text-emerald-400">{{ stats.completed }}</div>
                    <div class="mt-1 h-1.5 rounded-full bg-zinc-800">
                        <div
                            class="h-full rounded-full bg-emerald-500 transition-all duration-300"
                            :style="{ width: `${progress}%` }"
                        />
                    </div>
                </div>
                <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4">
                    <div class="text-xs font-medium text-zinc-500">Workers</div>
                    <div class="mt-1 font-mono text-2xl font-bold text-violet-400">{{ stats.peakWorkers }}</div>
                    <div class="mt-1 flex items-center gap-2 text-[10px] text-zinc-500">
                        <span v-if="stats.activeWorkers > 0" class="text-emerald-400">{{ stats.activeWorkers }} active</span>
                        <span>{{ stats.uniqueWorkers }} total</span>
                    </div>
                </div>
                <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4">
                    <div class="text-xs font-medium text-zinc-500">Avg Wait</div>
                    <div class="mt-1 font-mono text-2xl font-bold text-amber-400">{{ formatMs(stats.avgWaitMs) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4">
                    <div class="text-xs font-medium text-zinc-500">Throughput</div>
                    <div class="mt-1 font-mono text-2xl font-bold text-cyan-400">
                        {{ stats.jobsPerSecond ? `${stats.jobsPerSecond}/s` : '-' }}
                    </div>
                </div>
            </div>

            <!-- Secondary Stats -->
            <div v-if="stats.total > 0" class="grid grid-cols-3 gap-3">
                <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4 text-center">
                    <div class="text-xs font-medium text-zinc-500">Avg Process Time</div>
                    <div class="mt-1 font-mono text-lg font-bold text-white">{{ formatMs(stats.avgProcessMs) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4 text-center">
                    <div class="text-xs font-medium text-zinc-500">Total Duration</div>
                    <div class="mt-1 font-mono text-lg font-bold text-white">{{ formatMs(stats.totalDurationMs) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4 text-center">
                    <div class="text-xs font-medium text-zinc-500">Still Pending</div>
                    <div class="mt-1 font-mono text-lg font-bold" :class="stats.pending > 0 ? 'text-amber-400' : 'text-zinc-600'">
                        {{ stats.pending }}
                    </div>
                </div>
            </div>

            <!-- Waterfall Timeline -->
            <div v-if="jobs.length > 0" class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-6">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="font-mono text-sm font-semibold text-zinc-300">Job Waterfall</h2>
                    <div class="flex items-center gap-4 text-xs text-zinc-500">
                        <span class="flex items-center gap-1.5">
                            <span class="inline-block h-2.5 w-2.5 rounded-sm bg-zinc-700" />
                            Waiting
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span class="inline-block h-2.5 w-2.5 rounded-sm bg-blue-500" />
                            Processing
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span class="inline-block h-2.5 w-2.5 animate-pulse rounded-sm bg-amber-500" />
                            Active
                        </span>
                    </div>
                </div>

                <!-- Time axis -->
                <div class="relative mb-2 flex justify-between px-14 text-[10px] font-mono text-zinc-600">
                    <span>0ms</span>
                    <span>{{ formatMs(timelineMax * 0.25) }}</span>
                    <span>{{ formatMs(timelineMax * 0.5) }}</span>
                    <span>{{ formatMs(timelineMax * 0.75) }}</span>
                    <span>{{ formatMs(timelineMax) }}</span>
                </div>

                <div class="max-h-[600px] space-y-px overflow-y-auto">
                    <div
                        v-for="job in jobs"
                        :key="job.id"
                        class="group flex items-center gap-2"
                    >
                        <span class="w-12 shrink-0 text-right font-mono text-[10px] text-zinc-600">
                            #{{ job.number }}
                        </span>
                        <div class="relative h-4 flex-1 rounded-sm bg-zinc-800/50">
                            <!-- Waiting bar (from 0 to pickup) -->
                            <div
                                v-if="job.startMs !== null"
                                class="absolute inset-y-0 left-0 rounded-l-sm bg-zinc-700/60"
                                :style="{ width: `${(job.startMs / timelineMax) * 100}%` }"
                            />
                            <!-- Processing bar -->
                            <div
                                v-if="job.startMs !== null && job.endMs !== null"
                                class="absolute inset-y-0 rounded-sm transition-all duration-200"
                                :style="{
                                    left: `${(job.startMs / timelineMax) * 100}%`,
                                    width: `${((job.endMs - job.startMs) / timelineMax) * 100}%`,
                                    backgroundColor: job.worker ? workerColors[job.worker] : '#3b82f6',
                                }"
                            />
                            <!-- Active processing (no end yet) -->
                            <div
                                v-else-if="job.startMs !== null && job.endMs === null"
                                class="absolute inset-y-0 animate-pulse rounded-sm bg-amber-500"
                                :style="{
                                    left: `${(job.startMs / timelineMax) * 100}%`,
                                    width: '3%',
                                    minWidth: '8px',
                                }"
                            />
                            <!-- Pending shimmer -->
                            <div
                                v-if="job.status === 'pending'"
                                class="absolute inset-0 animate-pulse rounded-sm bg-zinc-700/30"
                            />
                        </div>
                    </div>
                </div>

                <!-- Worker Legend -->
                <div v-if="Object.keys(workerColors).length > 1" class="mt-4 flex flex-wrap gap-3 border-t border-zinc-800 pt-4">
                    <span class="text-[10px] font-medium text-zinc-500">Workers:</span>
                    <span
                        v-for="(color, worker) in workerColors"
                        :key="worker"
                        class="flex items-center gap-1.5 text-[10px] text-zinc-400"
                    >
                        <span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: color }" />
                        {{ (worker as string).split(':').pop() }}
                    </span>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else-if="!batchId" class="rounded-xl border border-dashed border-zinc-800 p-16 text-center">
                <div class="font-mono text-sm text-zinc-600">
                    Dispatch some jobs to see the waterfall visualization
                </div>
            </div>

            <!-- Recent Batches -->
            <div v-if="recentBatches.length > 0" class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-6">
                <h2 class="mb-3 font-mono text-sm font-semibold text-zinc-300">Recent Batches</h2>
                <div class="space-y-1">
                    <button
                        v-for="batch in recentBatches"
                        :key="batch.id"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left transition hover:bg-zinc-800"
                        :class="batchId === batch.id ? 'bg-zinc-800 text-white' : 'text-zinc-400'"
                        @click="selectBatch(batch.id)"
                    >
                        <span class="font-mono text-xs">{{ batch.id }}</span>
                        <span class="flex items-center gap-3 text-xs">
                            <span class="text-zinc-500">{{ batch.jobCount }} jobs</span>
                            <span class="text-violet-400/70">{{ batch.uniqueWorkers }} {{ batch.uniqueWorkers === 1 ? 'worker' : 'workers' }}</span>
                            <span class="text-zinc-600">{{ batch.dispatchedAt }}</span>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
