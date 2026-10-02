<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ScoreBadge from '@/Components/ScoreBadge.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';
import api from '@/api';
import { providerMeta } from '@/aiProviders';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

const props = defineProps({
    callId: { type: [Number, String], required: true },
});

const call = ref(null);
const loading = ref(true);
const error = ref('');
const processing = ref(false);
const reviewProcessing = ref(false);
const audioRef = ref(null);
const currentTime = ref(0);
const pollTimer = ref(null);
const expandedSegments = ref([]);
const selectedSegmentIndexes = ref([]);
const segmentForms = reactive({});
const segmentSaving = reactive({});
const segmentErrors = reactive({});
const bulkScoreSaving = ref(false);
const bulkScoreError = ref('');
const bulkScoreForm = reactive({
    manual_courtesy_score: '',
    manual_rudeness_score: '',
    manual_emotional_tone_score: '',
});

const segmentScoreFields = [
    { geminiKey: 'courtesy_score', manualKey: 'manual_courtesy_score', serKey: 'ser_courtesy_score', label: 'Nəzakət' },
    { geminiKey: 'rudeness_score', manualKey: 'manual_rudeness_score', serKey: 'ser_rudeness_score', label: 'Kobudluq' },
    { geminiKey: 'emotional_tone_score', manualKey: 'manual_emotional_tone_score', serKey: 'ser_emotional_tone_score', label: 'Emosional ton' },
];

const FAKE_SCORE_TOOLTIP = 'Auto-generated fallback score — not analyzed by AI';

const EVENT_SCORE_POINTS = 10;

const reviewForm = reactive({
    manual_courtesy_score: '',
    manual_operator_interruption_score: '',
    manual_customer_interruption_score: '',
    manual_rudeness_score: '',
    manual_emotional_tone_score: '',
    manual_operator_inappropriate_score: '',
    manual_customer_inappropriate_score: '',
    manual_note: '',
});

const reviewScoreFields = [
    { key: 'manual_courtesy_score', label: 'Nəzakət', hint: '0–100; yüksək xal daha nəzakətlidir.' },
    { key: 'manual_operator_interruption_score', label: 'Operator sözkəsmə', hint: '0–100; yüksək xal daha az operator kəsməsi deməkdir.' },
    { key: 'manual_customer_interruption_score', label: 'Müştəri sözkəsmə', hint: '0–100; yüksək xal daha az müştəri kəsməsi deməkdir.' },
    { key: 'manual_rudeness_score', label: 'Kobudluq', hint: '0–100; yüksək xal daha çox kobudluq deməkdir.' },
    { key: 'manual_emotional_tone_score', label: 'Emosional ton', hint: '0–100; yüksək xal daha həyəcanlı/mənfi tondur.' },
    { key: 'manual_operator_inappropriate_score', label: 'Operator uyğunsuz ifadələr', hint: '0–100; yüksək xal daha az operator uyğunsuz ifadə deməkdir.' },
    { key: 'manual_customer_inappropriate_score', label: 'Müştəri uyğunsuz ifadələr', hint: '0–100; yüksək xal daha az müştəri uyğunsuz ifadə deməkdir.' },
];

const reviewErrors = reactive({});

const clearReviewErrors = () => {
    Object.keys(reviewErrors).forEach((key) => delete reviewErrors[key]);
};

const mapReviewErrors = (responseErrors = {}) => {
    clearReviewErrors();
    Object.entries(responseErrors).forEach(([key, messages]) => {
        reviewErrors[key] = Array.isArray(messages) ? messages[0] : messages;
    });
};

const formatTimestamp = (seconds) => {
    if (seconds === null || seconds === undefined || Number.isNaN(Number(seconds))) {
        return '—';
    }

    const total = Math.max(0, Math.floor(Number(seconds)));
    const mins = Math.floor(total / 60);
    const secs = total % 60;

    return `${mins}:${String(secs).padStart(2, '0')}`;
};

const transcriptSegments = computed(() => {
    const segments = call.value?.analysis?.transcript_segments;

    return Array.isArray(segments) ? segments.filter((segment) => segment?.text) : [];
});

const reviewedSegmentCount = computed(() => transcriptSegments.value.filter((segment) => segment.manual_reviewed).length);

const isSegmentExpanded = (index) => expandedSegments.value.includes(index);

const allSegmentsExpanded = computed(() => {
    const segments = transcriptSegments.value;

    if (segments.length === 0) {
        return false;
    }

    return segments.every((_, index) => isSegmentExpanded(index));
});

const buildSegmentForm = (segment) => ({
    manual_courtesy_score: segmentScoreDefault(segment, 'manual_courtesy_score', 'courtesy_score'),
    manual_rudeness_score: segmentScoreDefault(segment, 'manual_rudeness_score', 'rudeness_score'),
    manual_emotional_tone_score: segmentScoreDefault(segment, 'manual_emotional_tone_score', 'emotional_tone_score'),
});

const segmentScoreDefault = (segment, manualKey, geminiKey) => {
    if (segment[manualKey] !== null && segment[manualKey] !== undefined && segment[manualKey] !== '') {
        return segment[manualKey];
    }

    if (segment[geminiKey] !== null && segment[geminiKey] !== undefined && segment[geminiKey] !== '') {
        return segment[geminiKey];
    }

    return '';
};

const hasSegmentAiScore = (segment, geminiKey) => segment[geminiKey] !== null
    && segment[geminiKey] !== undefined
    && segment[geminiKey] !== '';

const hasSegmentSerScore = (segment, serKey) => segment[serKey] !== null
    && segment[serKey] !== undefined
    && segment[serKey] !== '';

const formatAiScoreReference = (segment, geminiKey) => {
    if (!hasSegmentAiScore(segment, geminiKey)) {
        return 'Analiz olunmayıb';
    }

    return String(segment[geminiKey]);
};

const formatSerScoreReference = (segment, serKey) => {
    if (!hasSegmentSerScore(segment, serKey)) {
        return 'Yoxdur';
    }

    const number = Number(segment[serKey]);

    if (!Number.isFinite(number)) {
        return 'Yoxdur';
    }

    return number.toFixed(1);
};

const toggleSegmentReview = (index) => {
    if (isSegmentExpanded(index)) {
        expandedSegments.value = expandedSegments.value.filter((value) => value !== index);

        return;
    }

    expandedSegments.value = [...expandedSegments.value, index];
    segmentForms[index] = buildSegmentForm(transcriptSegments.value[index]);
    delete segmentErrors[index];
};

const toggleAllSegments = () => {
    if (allSegmentsExpanded.value) {
        expandedSegments.value = [];

        return;
    }

    transcriptSegments.value.forEach((segment, index) => {
        if (!isSegmentExpanded(index)) {
            segmentForms[index] = buildSegmentForm(segment);
            delete segmentErrors[index];
        }
    });

    expandedSegments.value = transcriptSegments.value.map((_, index) => index);
};

const segmentCheckboxClass = 'field-checkbox';

const selectedSegmentCount = computed(() => selectedSegmentIndexes.value.length);

const allSegmentsSelected = computed(() => {
    const count = transcriptSegments.value.length;

    return count > 0 && selectedSegmentIndexes.value.length === count;
});

const someSegmentsSelected = computed(() => selectedSegmentCount.value > 0 && !allSegmentsSelected.value);

const isSegmentSelected = (index) => selectedSegmentIndexes.value.includes(index);

const toggleSegmentSelection = (index) => {
    if (isSegmentSelected(index)) {
        selectedSegmentIndexes.value = selectedSegmentIndexes.value.filter((value) => value !== index);

        return;
    }

    selectedSegmentIndexes.value = [...selectedSegmentIndexes.value, index];
};

const toggleSelectAllSegments = () => {
    if (allSegmentsSelected.value) {
        selectedSegmentIndexes.value = [];

        return;
    }

    selectedSegmentIndexes.value = transcriptSegments.value.map((_, index) => index);
};

const resetBulkScoreForm = () => {
    bulkScoreForm.manual_courtesy_score = '';
    bulkScoreForm.manual_rudeness_score = '';
    bulkScoreForm.manual_emotional_tone_score = '';
    bulkScoreError.value = '';
};

const bulkScoreValue = (value) => {
    if (value === '' || value === null || value === undefined) {
        return null;
    }

    return Number(value);
};

const submitBulkScore = async () => {
    bulkScoreSaving.value = true;
    bulkScoreError.value = '';
    error.value = '';

    try {
        const { data } = await api.patch(`/calls/${props.callId}/segments/bulk-review`, {
            segment_ids: selectedSegmentIndexes.value,
            manual_courtesy_score: bulkScoreValue(bulkScoreForm.manual_courtesy_score),
            manual_rudeness_score: bulkScoreValue(bulkScoreForm.manual_rudeness_score),
            manual_emotional_tone_score: bulkScoreValue(bulkScoreForm.manual_emotional_tone_score),
        });

        (data.segments ?? []).forEach(({ id, segment }) => {
            if (call.value?.analysis?.transcript_segments?.[id]) {
                call.value.analysis.transcript_segments[id] = segment;
            }

            if (segmentForms[id]) {
                segmentForms[id] = buildSegmentForm(segment);
            }
        });

        selectedSegmentIndexes.value = [];
        resetBulkScoreForm();
    } catch (e) {
        if (e.response?.status === 422 && e.response.data?.errors) {
            bulkScoreError.value = Object.values(e.response.data.errors)
                .flat()
                .join(' ');
        } else {
            bulkScoreError.value = e.response?.data?.message || 'Seqment qiymətləndirməsi saxlanılmadı';
        }
    } finally {
        bulkScoreSaving.value = false;
    }
};

const submitSegmentReview = async (index) => {
    segmentSaving[index] = true;
    delete segmentErrors[index];
    error.value = '';

    const form = segmentForms[index] ?? {};

    try {
        const { data } = await api.patch(`/calls/${props.callId}/segments/${index}/review`, {
            manual_courtesy_score: form.manual_courtesy_score === '' ? null : Number(form.manual_courtesy_score),
            manual_rudeness_score: form.manual_rudeness_score === '' ? null : Number(form.manual_rudeness_score),
            manual_emotional_tone_score: form.manual_emotional_tone_score === '' ? null : Number(form.manual_emotional_tone_score),
        });

        if (call.value?.analysis?.transcript_segments?.[index]) {
            call.value.analysis.transcript_segments[index] = data.segment;
        }

        segmentForms[index] = buildSegmentForm(data.segment);
    } catch (e) {
        if (e.response?.status === 422 && e.response.data?.errors) {
            segmentErrors[index] = Object.values(e.response.data.errors)
                .flat()
                .join(' ');
        } else {
            segmentErrors[index] = e.response?.data?.message || 'Seqment qiymətləndirməsi saxlanılmadı';
        }
    } finally {
        segmentSaving[index] = false;
    }
};

const activeSegmentIndex = computed(() => {
    const segments = transcriptSegments.value;

    if (!segments.length) {
        return -1;
    }

    const time = currentTime.value;
    let active = -1;

    for (let index = 0; index < segments.length; index += 1) {
        const start = parseSegmentSeconds(segments[index].start) ?? 0;
        const end = segments[index].end !== null && segments[index].end !== undefined
            ? parseSegmentSeconds(segments[index].end)
            : null;

        if (time >= start && (end === null || time < end)) {
            active = index;
            break;
        }

        if (time >= start) {
            active = index;
        }
    }

    return active;
});

const transcriptEmptyMessage = computed(() => {
    if (!call.value) {
        return 'Transkript yoxdur.';
    }

    if (call.value.status === 'processing') {
        return 'Transkript hazırlanır — bir az gözləyin.';
    }

    if (call.value.status === 'pending') {
        return 'Zəng növbədədir. Analiz başlayanda transkript burada görünəcək.';
    }

    if (call.value.status === 'failed') {
        return call.value.error_message || 'Transkripsiya uğursuz oldu. Yenidən analiz edə bilərsiniz.';
    }

    return 'Transkript hələ hazır deyil.';
});

const stopPolling = () => {
    if (pollTimer.value) {
        clearInterval(pollTimer.value);
        pollTimer.value = null;
    }
};

const startPollingIfNeeded = () => {
    stopPolling();

    if (!call.value || !['pending', 'processing'].includes(call.value.status)) {
        return;
    }

    pollTimer.value = setInterval(() => {
        loadCall({ silent: true });
    }, 5000);
};

const loadCall = async ({ silent = false } = {}) => {
    if (!silent) {
        loading.value = true;
    }

    error.value = '';

    try {
        const { data } = await api.get(`/calls/${props.callId}`);
        call.value = data.call;
        fillReviewForm(data.call.analysis);
        startPollingIfNeeded();
    } catch (e) {
        error.value = e.response?.data?.message || 'Zəng yüklənmədi';
    } finally {
        if (!silent) {
            loading.value = false;
        }
    }
};

const onAudioTimeUpdate = () => {
    currentTime.value = audioRef.value?.currentTime ?? 0;
};

const parseSegmentSeconds = (value) => {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    if (typeof value === 'number' && Number.isFinite(value)) {
        return Math.max(0, value);
    }

    const text = String(value).trim();

    if (text === '') {
        return null;
    }

    if (/^\d+(\.\d+)?s$/i.test(text)) {
        const parsed = parseFloat(text);

        return Number.isFinite(parsed) ? Math.max(0, parsed) : null;
    }

    if (/^\d+(\.\d+)?ms$/i.test(text)) {
        const parsed = parseFloat(text);

        return Number.isFinite(parsed) ? Math.max(0, parsed / 1000) : null;
    }

    if (text.includes(':')) {
        const parts = text.split(':').map((part) => Number(part));

        if (parts.some((part) => Number.isNaN(part))) {
            return null;
        }

        if (parts.length === 2) {
            return Math.max(0, (parts[0] * 60) + parts[1]);
        }

        if (parts.length === 3) {
            return Math.max(0, (parts[0] * 3600) + (parts[1] * 60) + parts[2]);
        }
    }

    const parsed = Number(text);

    return Number.isFinite(parsed) ? Math.max(0, parsed) : null;
};

const seekToSegment = (index) => {
    const segment = transcriptSegments.value[index];

    if (!segment) {
        return;
    }

    const seconds = parseSegmentSeconds(segment.start);
    const audio = audioRef.value;

    if (seconds === null || !audio) {
        return;
    }

    const performSeek = () => {
        let completed = false;

        const finishSeek = () => {
            if (completed) {
                return;
            }

            completed = true;
            audio.removeEventListener('seeked', finishSeek);
            currentTime.value = audio.currentTime;

            if (audio.paused) {
                audio.play().catch(() => {});
            }
        };

        audio.addEventListener('seeked', finishSeek);
        audio.currentTime = seconds;

        // seeked may not fire when the position is already correct or seek is instant
        requestAnimationFrame(() => {
            if (Math.abs(audio.currentTime - seconds) < 0.05) {
                finishSeek();
            }
        });
    };

    if (audio.readyState >= 1) {
        performSeek();

        return;
    }

    audio.addEventListener('loadedmetadata', performSeek, { once: true });
};

const submitReview = async () => {
    reviewProcessing.value = true;
    clearReviewErrors();
    error.value = '';
    try {
        await api.patch(`/calls/${props.callId}/review`, {
            manual_courtesy_score: Number(reviewForm.manual_courtesy_score),
            manual_operator_interruption_score: Number(reviewForm.manual_operator_interruption_score),
            manual_customer_interruption_score: Number(reviewForm.manual_customer_interruption_score),
            manual_rudeness_score: Number(reviewForm.manual_rudeness_score),
            manual_emotional_tone_score: Number(reviewForm.manual_emotional_tone_score),
            manual_operator_inappropriate_score: Number(reviewForm.manual_operator_inappropriate_score),
            manual_customer_inappropriate_score: Number(reviewForm.manual_customer_inappropriate_score),
            manual_note: reviewForm.manual_note,
        });
        await loadCall();
    } catch (e) {
        if (e.response?.status === 422 && e.response.data?.errors) {
            mapReviewErrors(e.response.data.errors);
        } else {
            error.value = e.response?.data?.message || 'Qiymətləndirmə saxlanılmadı';
        }
    } finally {
        reviewProcessing.value = false;
    }
};

const reprocess = async () => {
    processing.value = true;
    error.value = '';
    try {
        await api.post(`/calls/${props.callId}/reprocess`);
        await loadCall();
    } catch (e) {
        error.value = e.response?.data?.message || 'Yenidən analiz uğursuz oldu';
    } finally {
        processing.value = false;
    }
};

const remove = async () => {
    if (!confirm('Bu zəngi silmək istəyirsiniz?')) return;
    processing.value = true;
    error.value = '';
    try {
        await api.delete(`/calls/${props.callId}`);
        router.visit(route('calls.index'));
    } catch (e) {
        error.value = e.response?.data?.message || 'Silinmə uğursuz oldu';
        processing.value = false;
    }
};

onMounted(loadCall);

onBeforeUnmount(stopPolling);

const scoreItems = [
    { key: 'courtesy_score', label: 'Nəzakət', format: 'percent' },
    { key: 'operator_interruption_count', label: 'Operator sözkəsmə', format: 'integer' },
    { key: 'customer_interruption_count', label: 'Müştəri sözkəsmə', format: 'integer' },
    { key: 'rudeness_score', label: 'Kobudluq', format: 'percent', inverse: true },
    { key: 'emotional_tone_score', label: 'Emosional ton', format: 'percent', inverse: true },
    { key: 'operator_inappropriate_words_count', label: 'Operator uyğunsuz ifadələr', format: 'integer' },
    { key: 'customer_inappropriate_words_count', label: 'Müştəri uyğunsuz ifadələr', format: 'integer' },
];

const serScoreItems = [
    { key: 'courtesy_score', label: 'Nəzakət' },
    { key: 'rudeness_score', label: 'Kobudluq' },
    { key: 'emotional_tone_score', label: 'Emosional ton' },
];

const formatSerScore = (value) => {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const number = Number(value);

    if (!Number.isFinite(number)) {
        return '—';
    }

    return `${number.toFixed(1)}%`;
};

const eventCountToScore = (count) => {
    if (count === null || count === undefined || count === '') {
        return '';
    }

    const number = Number(count);

    if (!Number.isFinite(number)) {
        return '';
    }

    return Math.max(0, 100 - (EVENT_SCORE_POINTS * number));
};

const fillReviewForm = (analysis) => {
    reviewForm.manual_courtesy_score = analysis?.manual_courtesy_score ?? analysis?.courtesy_score ?? '';
    reviewForm.manual_operator_interruption_score = analysis?.manual_operator_interruption_score
        ?? eventCountToScore(analysis?.operator_interruption_count);
    reviewForm.manual_customer_interruption_score = analysis?.manual_customer_interruption_score
        ?? eventCountToScore(analysis?.customer_interruption_count);
    reviewForm.manual_rudeness_score = analysis?.manual_rudeness_score ?? analysis?.rudeness_score ?? '';
    reviewForm.manual_emotional_tone_score = analysis?.manual_emotional_tone_score ?? analysis?.emotional_tone_score ?? '';
    reviewForm.manual_operator_inappropriate_score = analysis?.manual_operator_inappropriate_score
        ?? analysis?.manual_inappropriate_score
        ?? eventCountToScore(analysis?.operator_inappropriate_words_count ?? analysis?.inappropriate_words_count);
    reviewForm.manual_customer_inappropriate_score = analysis?.manual_customer_inappropriate_score
        ?? analysis?.manual_inappropriate_score
        ?? eventCountToScore(analysis?.customer_inappropriate_words_count ?? analysis?.inappropriate_words_count);
    reviewForm.manual_note = analysis?.manual_note ?? '';
};

const computedSupervisorScore = computed(() => call.value?.analysis?.manual_score ?? null);

const formatIntegerScore = (value) => {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return String(value);
};

const formatSpeakerLabel = (speaker) => {
    if (speaker === 'operator') {
        return 'Operator';
    }

    if (speaker === 'musteri' || speaker === 'customer') {
        return 'Müştəri';
    }

    if (!speaker) {
        return 'Naməlum';
    }

    const spkMatch = String(speaker).match(/^spk[:_]?(\d+)$/i);

    if (spkMatch) {
        return Number(spkMatch[1]) === 0 ? 'Operator' : 'Müştəri';
    }

    return String(speaker);
};

const isOperatorSpeaker = (speaker) => {
    if (speaker === 'operator') {
        return true;
    }

    if (speaker === 'musteri' || speaker === 'customer') {
        return false;
    }

    const spkMatch = String(speaker).match(/^spk[:_]?(\d+)$/i);

    return spkMatch ? Number(spkMatch[1]) === 0 : false;
};

const textareaClass = 'field-control mt-1 min-h-[96px] w-full py-2';
</script>

<template>
    <Head :title="call ? `Zəng #${call.id}` : 'Zəng'" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <Link :href="route('calls.index')" class="text-sm text-foreground hover:underline">← Zənglər</Link>
                    <h2 class="mt-1 page-title">
                        {{ call?.customer_name || 'Zəng detalları' }} <span v-if="call">#{{ call.id }}</span>
                    </h2>
                </div>
                <div v-if="call" class="flex flex-wrap gap-2">
                    <SecondaryButton type="button" :disabled="processing" @click="reprocess">Yenidən analiz</SecondaryButton>
                    <button type="button" class="inline-flex h-10 items-center rounded-lg bg-red-600 px-4 text-sm font-medium text-on-solid hover:bg-red-500 disabled:opacity-50" :disabled="processing" @click="remove">Sil</button>
                </div>
            </div>
        </template>

        <div v-if="loading" class="py-10 text-center text-sm text-muted-foreground">Yüklənir...</div>
        <div v-else-if="error && !call" class="alert-error">{{ error }}</div>

        <div v-else-if="call" class="space-y-6">
            <div v-if="error" class="alert-error">{{ error }}</div>

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="space-y-6 lg:col-span-2">
                    <div class="panel-card">
                        <div class="panel-card-header">
                            <div class="flex flex-wrap items-center gap-3">
                                <StatusBadge :status="call.status" />
                                <ScoreBadge :score="call.analysis?.effective_score" />
                                <span
                                    v-if="call.analysis?.is_fake_scored"
                                    class="badge-warn"
                                    :title="FAKE_SCORE_TOOLTIP"
                                >
                                    Fake / Backfill
                                </span>
                                <span class="text-sm font-normal text-muted-foreground">{{ call.called_at || call.created_at }}</span>
                            </div>
                        </div>

                        <div class="panel-card-body grid gap-3 p-4 text-sm sm:grid-cols-2">
                            <div><span class="text-muted-foreground">Operator:</span> <span class="font-medium">{{ call.operator?.name || '—' }}</span></div>
                            <div><span class="text-muted-foreground">Telefon:</span> <span class="font-medium">{{ call.customer_phone || '—' }}</span></div>
                            <div><span class="text-muted-foreground">Müddət:</span> <span class="font-medium">{{ call.duration }}</span></div>
                            <div><span class="text-muted-foreground">Dil:</span> <span class="font-medium">{{ call.language }}</span></div>
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-muted-foreground sm:col-span-2">
                                <span class="whitespace-nowrap">Transkript: {{ providerMeta(call.transcription_provider, call.transcription_model) }}</span>
                                <span aria-hidden="true">·</span>
                                <span class="whitespace-nowrap">Analiz: {{ providerMeta(call.analysis_provider, call.analysis_model) }}</span>
                            </div>
                        </div>

                        <div v-if="call.error_message" class="mt-4 alert-error">
                            {{ call.error_message }}
                        </div>
                    </div>

                    <div class="panel-card overflow-hidden">
                        <div class="panel-card-header">
                            <div>
                                <h3 class="font-semibold">Audio dinlə</h3>
                                <p class="mt-1 text-sm font-normal text-muted-foreground">
                                    {{ call.audio_original_name || 'Zəng yazısı' }}
                                </p>
                            </div>
                        </div>

                        <div v-if="call.audio_url" class="panel-card-body space-y-3 bg-bubble-received p-4">
                            <audio
                                :key="call.id"
                                ref="audioRef"
                                controls
                                preload="auto"
                                class="w-full accent-foreground"
                                :src="call.audio_url"
                                @timeupdate="onAudioTimeUpdate"
                                @loadedmetadata="onAudioTimeUpdate"
                            >
                                Brauzeriniz audio oxutmanı dəstəkləmir.
                            </audio>
                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                                <span>Cari vaxt: {{ formatTimestamp(currentTime) }}</span>
                                <a :href="call.audio_url" target="_blank" class="text-foreground hover:underline">Yeni tabda aç</a>
                            </div>
                        </div>
                        <div v-else class="px-5 py-8 text-sm text-muted-foreground">
                            Bu zəng üçün audio fayl tapılmadı.
                        </div>
                    </div>

                    <div class="panel-card overflow-hidden">
                        <div class="panel-card-header">
                            <div>
                                <h3 class="font-semibold">Transkript</h3>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    Seqmentə klik edərək audioyu həmin yerə keçirin. Qiymətləndirmə üçün sağdakı ox düyməsini istifadə edin.
                                </p>
                            </div>
                            <div class="ml-auto flex flex-col items-end gap-2">
                                <SecondaryButton
                                    v-if="transcriptSegments.length"
                                    type="button"
                                    @click="toggleAllSegments"
                                >
                                    {{ allSegmentsExpanded ? 'Hamısını yığ' : 'Hamısını Aç' }}
                                </SecondaryButton>
                                <div class="flex flex-col items-end gap-1 text-xs text-muted-foreground">
                                    <span v-if="transcriptSegments.length">
                                        Yoxlanılıb: {{ reviewedSegmentCount }}/{{ transcriptSegments.length }}
                                    </span>
                                    <span v-if="call.analysis?.analyzed_at">
                                        Analiz: {{ call.analysis.analyzed_at }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="transcriptSegments.length"
                            class="flex flex-wrap items-center gap-3 border-b border-border px-5 py-3"
                        >
                            <label class="inline-flex items-center gap-2 text-sm text-foreground">
                                <input
                                    type="checkbox"
                                    :class="segmentCheckboxClass"
                                    :checked="allSegmentsSelected"
                                    :indeterminate.prop="someSegmentsSelected"
                                    aria-label="Select All"
                                    @change="toggleSelectAllSegments"
                                />
                                Hamısını Seç
                            </label>
                            <span v-if="selectedSegmentCount" class="text-xs text-muted-foreground">
                                {{ selectedSegmentCount }} seçildi
                            </span>
                        </div>

                        <div
                            v-if="selectedSegmentCount"
                            class="border-b border-border bg-card-header px-5 py-4"
                        >
                            <h4 class="text-sm font-semibold">Toplu Skorla</h4>
                            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                                <div v-for="field in segmentScoreFields" :key="`bulk-${field.manualKey}`">
                                    <InputLabel :for="`bulk-${field.manualKey}`" :value="`${field.label} (manual)`" />
                                    <TextInput
                                        :id="`bulk-${field.manualKey}`"
                                        v-model="bulkScoreForm[field.manualKey]"
                                        type="number"
                                        min="0"
                                        max="100"
                                        class="mt-1 block w-full"
                                    />
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap items-center gap-3">
                                <PrimaryButton
                                    type="button"
                                    :disabled="bulkScoreSaving"
                                    @click="submitBulkScore"
                                >
                                    Saxla
                                </PrimaryButton>
                                <p v-if="bulkScoreError" class="text-sm text-danger">
                                    {{ bulkScoreError }}
                                </p>
                            </div>
                        </div>

                        <div v-if="transcriptSegments.length" class="divide-y divide-border">
                            <div
                                v-for="(segment, index) in transcriptSegments"
                                :key="`${segment.start}-${index}`"
                                class="transition"
                                :class="activeSegmentIndex === index ? 'bg-accent' : ''"
                            >
                                <div class="flex gap-3 px-5 py-3">
                                    <input
                                        type="checkbox"
                                        :class="[segmentCheckboxClass, 'mt-1 shrink-0']"
                                        :checked="isSegmentSelected(index)"
                                        :aria-label="`Select segment ${index + 1}`"
                                        @click.stop
                                        @change="toggleSegmentSelection(index)"
                                    />
                                    <div
                                        class="mt-1 w-1 shrink-0 self-stretch rounded-full"
                                        :class="isOperatorSpeaker(segment.speaker) ? 'bg-foreground' : 'bg-chart-line'"
                                    />
                                    <button
                                        type="button"
                                        class="min-w-0 flex-1 text-left transition hover:bg-accent rounded-lg -mx-2 px-2 py-1"
                                        @click="seekToSegment(index)"
                                    >
                                        <div class="mb-1 flex flex-wrap items-center gap-2">
                                            <span
                                                class="text-xs font-semibold"
                                                :class="isOperatorSpeaker(segment.speaker) ? 'text-foreground' : 'text-muted-foreground'"
                                            >
                                                {{ formatSpeakerLabel(segment.speaker) }}
                                            </span>
                                            <span class="font-mono text-xs text-foreground">
                                                {{ formatTimestamp(segment.start) }}
                                                <template v-if="segment.end !== null && segment.end !== undefined">
                                                    – {{ formatTimestamp(segment.end) }}
                                                </template>
                                            </span>
                                            <span
                                                v-if="segment.manual_reviewed"
                                                class="badge-success"
                                            >
                                                Yoxlanılıb
                                            </span>
                                            <span
                                                v-if="segment.is_fake_scored"
                                                class="badge-warn"
                                                :title="FAKE_SCORE_TOOLTIP"
                                            >
                                                Fake
                                            </span>
                                        </div>
                                        <p
                                            class="mt-1 block w-fit max-w-full rounded-xl px-3 py-2 text-sm leading-relaxed"
                                            :class="isOperatorSpeaker(segment.speaker) ? 'ml-auto bg-bubble-sent text-bubble-sent-foreground' : 'bg-bubble-received text-foreground'"
                                        >{{ segment.text }}</p>
                                    </button>
                                    <button
                                        type="button"
                                        class="mt-0.5 shrink-0 rounded-md p-2 text-muted-foreground transition hover:bg-accent hover:text-foreground"
                                        :aria-expanded="isSegmentExpanded(index)"
                                        :aria-label="isSegmentExpanded(index) ? 'Qiymətləndirməni bağla' : 'Seqmenti qiymətləndir'"
                                        @click.stop="toggleSegmentReview(index)"
                                    >
                                        <svg
                                            class="h-4 w-4 transition-transform"
                                            :class="isSegmentExpanded(index) ? 'rotate-180' : ''"
                                            viewBox="0 0 20 20"
                                            fill="currentColor"
                                            aria-hidden="true"
                                        >
                                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd" />
                                        </svg>
                                    </button>
                                </div>

                                <div
                                    v-if="isSegmentExpanded(index)"
                                    class="border-t border-border bg-card-header px-5 py-4"
                                >
                                    <div class="mb-3 space-y-2">
                                        <div
                                            v-for="field in segmentScoreFields"
                                            :key="field.manualKey"
                                            class="grid gap-2 sm:grid-cols-2"
                                        >
                                            <div class="rounded-lg bg-bubble-received px-3 py-2 text-xs">
                                                <div class="text-muted-foreground">{{ field.label }} (AI)</div>
                                                <div
                                                    class="mt-1 font-semibold"
                                                    :class="hasSegmentAiScore(segment, field.geminiKey) ? 'text-foreground' : 'text-warn italic'"
                                                >
                                                    {{ formatAiScoreReference(segment, field.geminiKey) }}
                                                </div>
                                            </div>
                                            <div class="rounded-lg bg-card-inner px-3 py-2 text-xs ring-1 ring-border">
                                                <div class="flex items-center gap-1.5 text-muted-foreground">
                                                    <span>{{ field.label }} (SER)</span>
                                                    <span class="rounded bg-accent px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-foreground">
                                                        səs
                                                    </span>
                                                </div>
                                                <div
                                                    class="mt-1 font-semibold"
                                                    :class="hasSegmentSerScore(segment, field.serKey) ? 'text-foreground' : 'text-muted-foreground italic'"
                                                >
                                                    {{ formatSerScoreReference(segment, field.serKey) }}
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <div v-for="field in segmentScoreFields" :key="`${field.manualKey}-input`">
                                            <InputLabel :for="`${field.manualKey}-${index}`" :value="`${field.label} (manual)`" />
                                            <TextInput
                                                :id="`${field.manualKey}-${index}`"
                                                v-model="segmentForms[index][field.manualKey]"
                                                type="number"
                                                min="0"
                                                max="100"
                                                class="mt-1 block w-full"
                                            />
                                        </div>
                                    </div>

                                    <div class="mt-3 flex flex-wrap items-center gap-3">
                                        <PrimaryButton
                                            type="button"
                                            :disabled="segmentSaving[index]"
                                            @click="submitSegmentReview(index)"
                                        >
                                            Saxla
                                        </PrimaryButton>
                                        <p v-if="segmentErrors[index]" class="text-sm text-danger">
                                            {{ segmentErrors[index] }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-else-if="call.analysis?.transcript" class="px-5 py-4">
                            <pre class="whitespace-pre-wrap rounded-xl bg-bubble-received p-4 text-sm leading-6 text-foreground ring-1 ring-border">{{ call.analysis.transcript }}</pre>
                        </div>

                        <div v-else class="px-5 py-8 text-sm text-muted-foreground">
                            {{ transcriptEmptyMessage }}
                        </div>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="panel-card">
                        <div class="panel-card-header">
                            <h3>Skorlar (0–100)</h3>
                        </div>
                        <div class="panel-card-body space-y-4 p-4">
                        <div class="space-y-3">
                            <div v-for="item in scoreItems" :key="item.key" class="flex items-center justify-between text-sm">
                                <span class="text-muted-foreground">{{ item.label }}</span>
                                <ScoreBadge
                                    v-if="item.format === 'percent'"
                                    :score="call.analysis?.[item.key]"
                                    :inverse="item.inverse"
                                    suffix="%"
                                />
                                <span v-else class="text-sm font-semibold text-foreground">{{ formatIntegerScore(call.analysis?.[item.key]) }}</span>
                            </div>
                            <div class="flex items-center justify-between border-t border-border pt-3 text-sm font-semibold">
                                <span class="flex items-center gap-2">
                                    Overall
                                    <span
                                        v-if="call.analysis?.is_fake_scored"
                                        class="badge-warn"
                                        :title="FAKE_SCORE_TOOLTIP"
                                    >
                                        Fake
                                    </span>
                                </span>
                                <ScoreBadge :score="call.analysis?.total_score" suffix="%" />
                            </div>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <span v-if="call.analysis?.has_rudeness" class="badge-orange">Kobudluq var</span>
                            <span v-if="call.analysis?.has_interruption" class="badge-warn">Söz kəsilib</span>
                            <span v-if="call.analysis?.emotion_label" class="badge-info">Emosiya: {{ call.analysis.emotion_label }}</span>
                            <span v-if="call.analysis?.buyer_potential_label" class="badge-success">Alıcı: {{ call.analysis.buyer_potential_label }}</span>
                        </div>
                        </div>
                    </div>

                    <div v-if="call.ser_score" class="panel-card">
                        <div class="panel-card-header">
                            <h3>Səs-əsaslı analiz (model: {{ call.ser_score.model_version }})</h3>
                        </div>
                        <div class="panel-card-body space-y-3 p-4">
                        <p class="text-xs text-muted-foreground">
                            Acoustic SER — Gemini skorlarından ayrı, müqayisə üçün paralel siqnal.
                        </p>
                        <div class="space-y-3">
                            <div
                                v-for="item in serScoreItems"
                                :key="`ser-${item.key}`"
                                class="flex items-center justify-between text-sm"
                            >
                                <span class="text-muted-foreground">{{ item.label }}</span>
                                <span class="font-semibold text-foreground">{{ formatSerScore(call.ser_score[item.key]) }}</span>
                            </div>
                            <div
                                v-if="call.ser_score.ser_overall !== null && call.ser_score.ser_overall !== undefined"
                                class="flex items-center justify-between border-t border-border pt-3 text-sm font-semibold"
                            >
                                <span class="flex items-center gap-2">
                                    Overall
                                </span>
                                <ScoreBadge :score="call.ser_score.ser_overall" suffix="%" />
                            </div>
                        </div>
                        <p v-if="call.ser_score.scored_at" class="mt-3 text-xs text-muted-foreground">
                            Qiymətləndirmə: {{ call.ser_score.scored_at }}
                        </p>
                        </div>
                    </div>

                    <form class="panel-card" @submit.prevent="submitReview">
                        <div class="panel-card-header">
                            <h3>Manual qiymətləndirmə</h3>
                        </div>
                        <div class="panel-card-body space-y-4 p-4">
                        <p class="text-sm text-muted-foreground">
                            Hər parametr 0–100 xalla qiymətləndirilir. Supervisor skoru Ayarlardakı çəkilərə görə avtomatik hesablanır.
                        </p>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div v-for="field in reviewScoreFields" :key="field.key">
                                <InputLabel :for="field.key" :value="field.label" />
                                <TextInput
                                    :id="field.key"
                                    v-model="reviewForm[field.key]"
                                    type="number"
                                    min="0"
                                    max="100"
                                    class="mt-1 block w-full"
                                />
                                <p class="mt-1 text-xs text-muted-foreground">{{ field.hint }}</p>
                                <InputError class="mt-2" :message="reviewErrors[field.key]" />
                            </div>
                        </div>
                        <div class="mt-4 flex items-center justify-between border-t border-border pt-3">
                            <div>
                                <div class="text-sm font-semibold">Supervisor skoru</div>
                                <p class="mt-1 text-xs text-muted-foreground">Saxlanılanda serverdə avtomatik hesablanır.</p>
                            </div>
                            <ScoreBadge :score="computedSupervisorScore" suffix="%" />
                        </div>
                        <div class="mt-3">
                            <InputLabel for="manual_note" value="Qeyd" />
                            <textarea
                                id="manual_note"
                                v-model="reviewForm.manual_note"
                                rows="4"
                                :class="textareaClass"
                            />
                            <InputError class="mt-2" :message="reviewErrors.manual_note" />
                        </div>
                        <PrimaryButton class="mt-4" :disabled="reviewProcessing">Saxla</PrimaryButton>
                        <p v-if="call.analysis?.reviewed_by" class="mt-2 text-xs text-muted-foreground">Son yoxlayan: {{ call.analysis.reviewed_by }}</p>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
