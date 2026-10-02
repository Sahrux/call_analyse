<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ScoreBadge from '@/Components/ScoreBadge.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    range: {
        type: String,
        default: '1M',
    },
    stats: {
        type: Object,
        required: true,
    },
    traffic: {
        type: Array,
        required: true,
    },
    chart: {
        type: Array,
        required: true,
    },
    activities: {
        type: Array,
        required: true,
    },
    recentCalls: {
        type: Array,
        required: true,
    },
    topOperators: {
        type: Array,
        default: () => [],
    },
    riskOperators: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const userName = computed(() => page.props.auth?.user?.name || 'Admin');
const loading = ref(false);
const selectedRange = ref(props.range);
const hovered = ref(null);
const chartSvg = ref(null);
const chartFrame = ref(null);
const tooltipEl = ref(null);
const svgWidth = ref(640);
const tooltipPos = ref({ left: 0, top: 0 });
let requestId = 0;
let chartObserver;

watch(
    () => props.range,
    (range) => {
        selectedRange.value = range;
    },
);

const ranges = [
    { key: '1D', label: '1D' },
    { key: '1W', label: '1W' },
    { key: '1M', label: '1M' },
];

const setRange = (range) => {
    if (range === selectedRange.value) {
        return;
    }

    selectedRange.value = range;
    const current = ++requestId;
    loading.value = true;

    router.get(route('dashboard'), { range }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['range', 'stats', 'traffic', 'chart', 'activities', 'recentCalls', 'topOperators', 'riskOperators'],
        onFinish: () => {
            if (current === requestId) {
                loading.value = false;
                hovered.value = null;
            }
        },
    });
};

const formatScore = (value) => {
    if (value === null || value === undefined) {
        return '—';
    }

    const number = Number(value);

    return Number.isInteger(number) ? String(number) : number.toFixed(1);
};

const sparkSegments = (points, scaleTo = null) => {
    const values = points.map((value) => (value === null || value === undefined ? null : Number(value)));
    const present = values.filter((value) => value !== null && !Number.isNaN(value));

    if (!present.length) {
        return [];
    }

    const max = scaleTo ?? Math.max(...present, 1);
    const segments = [];
    let current = [];

    values.forEach((value, index) => {
        if (value === null || Number.isNaN(value)) {
            if (current.length) {
                segments.push(current);
            }
            current = [];
            return;
        }

        const x = (index / Math.max(values.length - 1, 1)) * 80;
        const y = 28 - (Math.min(value, max) / max) * 22;
        current.push([x, y]);
    });

    if (current.length) {
        segments.push(current);
    }

    return segments.map((segment) => {
        const drawn = segment.length === 1
            ? [[segment[0][0] - 1, segment[0][1]], [segment[0][0] + 1, segment[0][1]]]
            : segment;

        return drawn.map(([x, y]) => `${x},${y}`).join(' ');
    });
};

const metricCards = computed(() => [
    {
        title: 'Ümumi zəng',
        value: props.stats.total_calls,
        hint: 'Completed + pending',
        showSpark: props.stats.total_calls > 0,
        segments: sparkSegments(props.stats.sparklines?.total_calls ?? []),
    },
    {
        title: 'Orta skor',
        value: formatScore(props.stats.avg_score),
        hint: 'Quality score',
        showSpark: props.stats.avg_score !== null && props.stats.avg_score !== undefined,
        segments: sparkSegments(props.stats.sparklines?.avg_score ?? [], 100),
    },
    {
        title: 'Problemli',
        value: props.stats.problematic_calls,
        hint: 'Aggression / rudeness',
        showSpark: props.stats.problematic_calls > 0,
        segments: sparkSegments(props.stats.sparklines?.problematic_calls ?? []),
    },
]);

const MIN_LABEL_GAP = 40;

const plot = {
    width: 640,
    height: 240,
    left: 44,
    right: 52,
    top: 16,
    bottom: 32,
};

const innerWidth = plot.width - plot.left - plot.right;
const innerHeight = plot.height - plot.top - plot.bottom;

const yTicks = [100, 75, 50, 25, 0].map((value) => ({
    value,
    y: plot.top + (1 - value / 100) * innerHeight,
}));

const plotPoints = computed(() => {
    const count = props.chart.length;

    return props.chart.map((point, index) => {
        const x = count === 1
            ? plot.left + innerWidth / 2
            : plot.left + (index / (count - 1)) * innerWidth;
        const score = point.avg_score === null || point.avg_score === undefined ? null : Number(point.avg_score);

        return {
            ...point,
            x,
            y: score === null ? null : plot.top + (1 - Math.min(Math.max(score, 0), 100) / 100) * innerHeight,
            score,
        };
    });
});

const lineSegments = computed(() => {
    const segments = [];
    let current = [];

    plotPoints.value.forEach((point) => {
        if (point.score === null) {
            if (current.length) {
                segments.push(current);
            }
            current = [];
            return;
        }

        current.push(point);
    });

    if (current.length) {
        segments.push(current);
    }

    return segments;
});

const pathFor = (segment) => {
    if (segment.length < 2) {
        return '';
    }

    return segment.map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x} ${point.y}`).join(' ');
};

const slotWidth = computed(() => {
    const count = Math.max(plotPoints.value.length, 1);

    return innerWidth / count;
});

const hasChartData = computed(() => props.chart.some((point) => point.calls_count > 0));

const trafficTotal = computed(() => props.traffic.reduce((sum, item) => sum + item.count, 0));

const donutStyle = computed(() => {
    if (trafficTotal.value === 0) {
        return { background: 'conic-gradient(var(--chart-line) 0% 100%)' };
    }

    let cursor = 0;
    const stops = props.traffic.map((item) => {
        const start = cursor;
        cursor += (item.count / trafficTotal.value) * 100;

        return `${item.color} ${start}% ${cursor}%`;
    });

    return { background: `conic-gradient(${stops.join(', ')})` };
});

const formatAxisLabel = (date) => {
    const parsed = new Date(date);

    if (Number.isNaN(parsed.getTime())) {
        return date;
    }

    if (String(date).includes('T')) {
        return parsed.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    }

    const day = String(parsed.getUTCDate()).padStart(2, '0');
    const month = String(parsed.getUTCMonth() + 1).padStart(2, '0');

    return `${day}.${month}`;
};

const formatTooltipDate = (date) => {
    const parsed = new Date(date);

    if (Number.isNaN(parsed.getTime())) {
        return date;
    }

    if (String(date).includes('T')) {
        return parsed.toLocaleString(undefined, {
            day: '2-digit',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    const day = String(parsed.getUTCDate()).padStart(2, '0');
    const month = String(parsed.getUTCMonth() + 1).padStart(2, '0');

    return `${day}.${month}.${parsed.getUTCFullYear()}`;
};

const screenX = (x) => (x / plot.width) * svgWidth.value;

const labelGap = (points, previousIndex, nextIndex) => {
    const fontPx = 11 * (svgWidth.value / plot.width);
    const previousWidth = formatAxisLabel(points[previousIndex].date).length * fontPx * 0.62;
    const nextWidth = formatAxisLabel(points[nextIndex].date).length * fontPx * 0.62;

    return Math.max(MIN_LABEL_GAP, (previousWidth + nextWidth) / 2 + 8);
};

const visibleLabelIndexes = computed(() => {
    const points = plotPoints.value;
    const count = points.length;

    if (count === 0) {
        return [];
    }

    const fontPx = 11 * (svgWidth.value / plot.width);
    const widest = points.reduce((max, point) => Math.max(max, formatAxisLabel(point.date).length), 0);
    const interval = Math.max(MIN_LABEL_GAP, widest * fontPx * 0.62 + 8);
    let step = 1;

    if (count > 1) {
        const span = screenX(points[count - 1].x) - screenX(points[0].x);
        const slots = Math.max(1, Math.floor(span / interval));
        step = Math.max(1, Math.ceil((count - 1) / slots));
    }

    const indexes = [];

    for (let index = 0; index < count; index += step) {
        indexes.push(index);
    }

    if (indexes[indexes.length - 1] !== count - 1) {
        indexes.push(count - 1);
    }

    while (indexes.length >= 2) {
        const previous = indexes[indexes.length - 2];
        const last = indexes[indexes.length - 1];
        const distance = screenX(points[last].x) - screenX(points[previous].x);

        if (distance >= labelGap(points, previous, last)) {
            break;
        }

        if (previous === 0) {
            indexes.pop();
            break;
        }

        indexes.splice(indexes.length - 2, 1);
    }

    return indexes;
});

const visibleLabelSet = computed(() => new Set(visibleLabelIndexes.value));

const labelX = (point) => {
    const half = (formatAxisLabel(point.date).length * 11 * 0.58) / 2;

    return Math.min(Math.max(point.x, half + 2), plot.width - half - 2);
};

const measureChart = () => {
    const width = chartSvg.value?.getBoundingClientRect().width;

    if (width) {
        svgWidth.value = width;
    }

    if (hovered.value) {
        moveTooltip();
    }
};

const bindChartObserver = () => {
    chartObserver?.disconnect();

    if (chartSvg.value) {
        chartObserver?.observe(chartSvg.value);
    }
};

const computeTooltipPos = (clientX, clientY) => {
    const width = tooltipEl.value?.offsetWidth || 176;
    const height = tooltipEl.value?.offsetHeight || 68;
    const margin = 8;
    const frame = chartFrame.value?.getBoundingClientRect();
    let minLeft = margin;
    let maxLeft = window.innerWidth - width - margin;

    if (frame) {
        const frameMin = frame.left + margin;
        const frameMax = frame.right - width - margin;

        if (frameMax >= frameMin) {
            minLeft = Math.max(minLeft, frameMin);
            maxLeft = Math.min(maxLeft, frameMax);
        }
    }

    if (maxLeft < minLeft) {
        minLeft = margin;
        maxLeft = window.innerWidth - width - margin;
    }

    const left = Math.min(Math.max(clientX - width / 2, minLeft), maxLeft);
    const above = clientY - height - 12;
    const below = clientY + 16;
    let top = above >= margin ? above : below;

    if (top + height > window.innerHeight - margin) {
        top = Math.max(margin, window.innerHeight - height - margin);
    }

    return { left, top };
};

const moveTooltip = () => {
    if (!hovered.value || !chartSvg.value) {
        return;
    }

    const bounds = chartSvg.value.getBoundingClientRect();
    const point = hovered.value;
    const clientX = bounds.left + (point.x / plot.width) * bounds.width;
    const yView = point.y === null ? plot.top + innerHeight / 2 : point.y;
    const clientY = bounds.top + (yView / plot.height) * bounds.height;

    tooltipPos.value = computeTooltipPos(clientX, clientY);
};

const showTooltip = async (point) => {
    if (chartSvg.value) {
        const bounds = chartSvg.value.getBoundingClientRect();
        const clientX = bounds.left + (point.x / plot.width) * bounds.width;
        const yView = point.y === null ? plot.top + innerHeight / 2 : point.y;
        const clientY = bounds.top + (yView / plot.height) * bounds.height;
        tooltipPos.value = computeTooltipPos(clientX, clientY);
    }

    hovered.value = point;
    await nextTick();
    moveTooltip();
};

const hideTooltip = () => {
    hovered.value = null;
};

onMounted(() => {
    chartObserver = new ResizeObserver(() => measureChart());
    bindChartObserver();
    measureChart();
    window.addEventListener('scroll', moveTooltip, true);
    window.addEventListener('resize', measureChart);
});

onUnmounted(() => {
    chartObserver?.disconnect();
    window.removeEventListener('scroll', moveTooltip, true);
    window.removeEventListener('resize', measureChart);
});

watch(() => props.chart, async () => {
    await nextTick();
    bindChartObserver();
    measureChart();
});

const activityTone = {
    completed: 'tone-success',
    alert: 'tone-danger',
    pending: 'tone-warn',
    call: 'tone-muted',
};
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="page-title truncate">Welcome {{ userName }}!</h1>
        </template>

        <div class="space-y-6">
            <div class="grid gap-6 sm:grid-cols-3">
                <template v-if="loading">
                    <div v-for="index in 3" :key="`card-skeleton-${index}`" class="panel-card">
                        <div class="panel-card-header">
                            <span class="h-4 w-24 animate-pulse rounded bg-accent" />
                        </div>
                        <div class="panel-card-body space-y-3 p-4">
                            <div class="h-9 w-16 animate-pulse rounded bg-accent" />
                            <div class="h-3 w-28 animate-pulse rounded bg-accent" />
                            <div class="h-10 w-full animate-pulse rounded bg-accent" />
                        </div>
                    </div>
                </template>
                <template v-else>
                    <div v-for="card in metricCards" :key="card.title" class="panel-card">
                    <div class="panel-card-header">
                        <span>{{ card.title }}</span>
                    </div>
                    <div class="panel-card-body p-4">
                        <div class="text-[34px] font-bold leading-none tracking-tight">{{ card.value }}</div>
                        <p class="mt-2 text-xs text-muted-foreground">{{ card.hint }}</p>
                        <svg v-if="card.showSpark" class="mt-4 h-10 w-full" viewBox="0 0 80 30" fill="none">
                            <polyline
                                v-for="(points, segmentIndex) in card.segments"
                                :key="segmentIndex"
                                :points="points"
                                stroke="var(--chart-strong)"
                                stroke-width="2"
                                fill="none"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                    </div>
                    </div>
                </template>
            </div>

            <div class="grid gap-6 xl:grid-cols-3">
                <div class="panel-card !overflow-visible xl:col-span-2">
                    <div class="panel-card-header overflow-hidden rounded-t-2xl">
                        <h3>Call Quality Analytics</h3>
                        <div class="flex flex-wrap gap-1">
                            <button
                                v-for="item in ranges"
                                :key="item.key"
                                type="button"
                                class="rounded-lg px-3 py-1 text-xs font-medium"
                                :class="selectedRange === item.key ? 'bg-primary text-primary-foreground' : 'border border-border bg-card-inner text-muted-foreground hover:bg-accent hover:text-foreground'"
                                @click="setRange(item.key)"
                            >
                                {{ item.label }}
                            </button>
                        </div>
                    </div>

                    <div v-if="loading" class="panel-card-body p-4">
                        <div class="h-64 animate-pulse rounded-lg bg-accent" />
                    </div>
                    <div v-else-if="!hasChartData" class="panel-card-body flex h-64 items-center justify-center p-4 text-sm text-muted-foreground">
                        No data for this period
                    </div>
                    <div v-else class="panel-card-body !overflow-visible p-4">
                        <div ref="chartFrame" class="relative">
                            <svg ref="chartSvg" :viewBox="`0 0 ${plot.width} ${plot.height}`" class="h-64 w-full overflow-visible" @mouseleave="hideTooltip">
                                <line
                                    v-for="tick in yTicks"
                                    :key="tick.value"
                                    :x1="plot.left"
                                    :x2="plot.width - plot.right"
                                    :y1="tick.y"
                                    :y2="tick.y"
                                    stroke="var(--border)"
                                    stroke-width="1"
                                />
                                <text
                                    v-for="tick in yTicks"
                                    :key="`label-${tick.value}`"
                                    :x="plot.left - 8"
                                    :y="tick.y + 4"
                                    text-anchor="end"
                                    fill="var(--muted-foreground)"
                                    font-size="11"
                                >
                                    {{ tick.value }}
                                </text>
                                <path
                                    v-for="(segment, index) in lineSegments"
                                    :key="`line-${index}`"
                                    :d="pathFor(segment)"
                                    fill="none"
                                    stroke="var(--chart-strong)"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                                <g v-for="(point, index) in plotPoints" :key="point.date">
                                    <rect
                                        :x="point.x - slotWidth / 2"
                                        :y="plot.top"
                                        :width="slotWidth"
                                        :height="innerHeight"
                                        fill="transparent"
                                        @mouseenter="showTooltip(point)"
                                    />
                                    <circle
                                        v-if="point.score !== null"
                                        :cx="point.x"
                                        :cy="point.y"
                                        r="4"
                                        fill="var(--chart-point)"
                                        stroke="var(--chart-strong)"
                                        stroke-width="1.5"
                                    />
                                    <text
                                        v-if="visibleLabelSet.has(index)"
                                        :x="labelX(point)"
                                        :y="plot.height - 8"
                                        text-anchor="middle"
                                        fill="var(--muted-foreground)"
                                        font-size="11"
                                    >
                                        {{ formatAxisLabel(point.date) }}
                                    </text>
                                </g>
                            </svg>
                        </div>
                    </div>
                </div>

                <div class="panel-card">
                    <div class="panel-card-header">
                        <h3>Traffic</h3>
                    </div>
                    <div v-if="loading" class="panel-card-body space-y-4 p-4">
                        <div class="mx-auto h-44 w-44 animate-pulse rounded-full bg-accent" />
                        <div class="h-4 animate-pulse rounded bg-accent" />
                        <div class="h-4 animate-pulse rounded bg-accent" />
                        <div class="h-4 animate-pulse rounded bg-accent" />
                    </div>
                    <div v-else class="panel-card-body p-4">
                        <div class="mx-auto flex h-44 w-44 items-center justify-center rounded-full" :style="donutStyle">
                            <div class="flex h-28 w-28 flex-col items-center justify-center rounded-full bg-card-inner text-center">
                                <template v-if="trafficTotal === 0">
                                    <div class="text-sm font-medium text-muted-foreground">No data</div>
                                </template>
                                <template v-else>
                                    <div class="text-2xl font-semibold">{{ stats.total_calls }}</div>
                                    <div class="text-xs text-muted-foreground">Calls</div>
                                </template>
                            </div>
                        </div>
                        <div v-if="trafficTotal === 0" class="mt-6 text-center text-sm text-muted-foreground">
                            No data for this period
                        </div>
                        <div v-else class="mt-6 space-y-3">
                            <div v-for="item in traffic" :key="item.key" class="flex items-center justify-between text-sm">
                                <div class="flex items-center gap-2">
                                    <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: item.color }" />
                                    <span class="text-muted-foreground">{{ item.label }}</span>
                                </div>
                                <span class="font-semibold">{{ item.percent }}%</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid gap-6 xl:grid-cols-3">
                <div class="panel-card">
                    <div class="panel-card-header">
                        <h3>Recent Activities</h3>
                    </div>
                    <div v-if="loading" class="panel-card-body space-y-4 p-4">
                        <div v-for="index in 3" :key="`activity-skeleton-${index}`" class="h-12 animate-pulse rounded bg-accent" />
                    </div>
                    <div v-else-if="!activities.length" class="panel-card-body p-4 text-sm text-muted-foreground">
                        No data for this period
                    </div>
                    <div v-else class="panel-card-body space-y-4 p-4">
                        <div v-for="activity in activities" :key="activity.id" class="flex gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold" :class="activityTone[activity.type]">•</div>
                            <div>
                                <div class="text-xs text-muted-foreground">{{ activity.time }}</div>
                                <div class="text-sm font-medium">{{ activity.title }}</div>
                                <div class="text-xs text-muted-foreground">{{ activity.subtitle }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="panel-card xl:col-span-2">
                    <div class="panel-card-header">
                        <h3>Call Status</h3>
                        <Link :href="route('calls.create')" class="inline-flex h-8 items-center gap-1 rounded-lg bg-primary px-3 text-xs font-medium text-primary-foreground hover:bg-primary-hover">+ Add</Link>
                    </div>
                    <div v-if="loading" class="panel-card-body space-y-3 p-4">
                        <div v-for="index in 4" :key="`row-skeleton-${index}`" class="h-8 animate-pulse rounded bg-accent" />
                    </div>
                    <div v-else-if="!recentCalls.length" class="panel-card-body p-4 text-sm text-muted-foreground">
                        No data for this period
                    </div>
                    <div v-else class="panel-card-body overflow-x-auto">
                        <table class="panel-table min-w-full">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Müştəri</th>
                                    <th>Operator</th>
                                    <th>Skor</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="call in recentCalls" :key="call.id">
                                    <td class="text-muted-foreground">#{{ call.id }}</td>
                                    <td>
                                        <Link :href="route('calls.show', call.id)" class="font-medium hover:underline">
                                            {{ call.customer_name || call.customer_phone || 'Adsız' }}
                                        </Link>
                                    </td>
                                    <td class="text-muted-foreground">{{ call.operator || '—' }}</td>
                                    <td><ScoreBadge :score="call.score" /></td>
                                    <td><StatusBadge :status="call.status" /></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="grid gap-6 md:grid-cols-2">
                <div class="panel-card">
                    <div class="panel-card-header">
                        <h3>Top operatorlar</h3>
                    </div>
                    <div v-if="loading" class="panel-card-body space-y-3 p-4">
                        <div v-for="index in 3" :key="`top-skeleton-${index}`" class="h-6 animate-pulse rounded bg-accent" />
                    </div>
                    <div v-else-if="!topOperators.length" class="panel-card-body p-4 text-sm text-muted-foreground">
                        No data for this period
                    </div>
                    <div v-else class="panel-card-body space-y-3 p-4">
                        <div v-for="op in topOperators" :key="op.id" class="flex items-center justify-between text-sm">
                            <span class="font-medium">{{ op.name }}</span>
                            <ScoreBadge :score="op.avg_score" />
                        </div>
                    </div>
                </div>
                <div class="panel-card">
                    <div class="panel-card-header">
                        <h3>Riskli operatorlar</h3>
                    </div>
                    <div v-if="loading" class="panel-card-body space-y-3 p-4">
                        <div v-for="index in 3" :key="`risk-skeleton-${index}`" class="h-6 animate-pulse rounded bg-accent" />
                    </div>
                    <div v-else-if="!riskOperators.length" class="panel-card-body p-4 text-sm text-muted-foreground">
                        No data for this period
                    </div>
                    <div v-else class="panel-card-body space-y-3 p-4">
                        <div v-for="op in riskOperators" :key="op.id" class="flex items-center justify-between text-sm">
                            <span class="font-medium">{{ op.name }}</span>
                            <ScoreBadge :score="op.avg_score" />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <Teleport to="body">
            <div
                v-if="hovered"
                ref="tooltipEl"
                class="pointer-events-none fixed z-[60] w-44 rounded-lg border border-border bg-card px-3 py-2 text-xs shadow-sm"
                :style="{ left: `${tooltipPos.left}px`, top: `${tooltipPos.top}px` }"
            >
                <div class="font-medium">{{ formatTooltipDate(hovered.date) }}</div>
                <div class="mt-1 text-muted-foreground">Avg score: {{ formatScore(hovered.avg_score) }}</div>
                <div class="text-muted-foreground">Calls: {{ hovered.calls_count }}</div>
            </div>
        </Teleport>
    </AuthenticatedLayout>
</template>
