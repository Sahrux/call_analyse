<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import ScoreBadge from '@/Components/ScoreBadge.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';
import api from '@/api';
import { providerLabel } from '@/aiProviders';
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';

const FAILED_STATUS = 'failed';

const loading = ref(true);
const error = ref('');
const success = ref('');
const calls = ref({ data: [], links: [], total: 0 });
const totalCount = ref(0);
const operators = ref([]);
const statuses = ref({});
const selectedIds = ref([]);
const reanalyzing = ref(false);

const form = reactive({
    search: '',
    status: '',
    operator_id: '',
    score_min: '',
    score_max: '',
    problem: '',
    fake_scored: '',
    transcription_provider: '',
    analysis_provider: '',
    page: 1,
});

const FAKE_SCORE_TOOLTIP = 'Auto-generated fallback score — not analyzed by AI';

const selectClass = 'field-select';

const selectableCalls = computed(() =>
    (calls.value.data || []).filter((call) => call.status === FAILED_STATUS),
);

const allVisibleSelected = computed(() => {
    const ids = selectableCalls.value.map((call) => call.id);
    return ids.length > 0 && ids.every((id) => selectedIds.value.includes(id));
});

const someVisibleSelected = computed(() => {
    const ids = selectableCalls.value.map((call) => call.id);
    const selectedVisible = ids.filter((id) => selectedIds.value.includes(id));
    return selectedVisible.length > 0 && selectedVisible.length < ids.length;
});

const filterDefaults = () => ({
    search: '',
    status: '',
    operator_id: '',
    score_min: '',
    score_max: '',
    problem: '',
    fake_scored: '',
    transcription_provider: '',
    analysis_provider: '',
    page: 1,
});

const readFiltersFromUrl = () => {
    const params = new URLSearchParams(window.location.search);
    const defaults = filterDefaults();
    Object.assign(form, defaults);

    Object.keys(defaults).forEach((key) => {
        if (!params.has(key)) {
            return;
        }

        form[key] = key === 'page' ? Number(params.get(key)) || 1 : params.get(key) ?? '';
    });
};

const syncFiltersToUrl = () => {
    const params = new URLSearchParams();

    Object.entries(form).forEach(([key, value]) => {
        if (value === '' || value === null || value === undefined) {
            return;
        }

        if (key === 'page' && Number(value) <= 1) {
            return;
        }

        params.set(key, String(value));
    });

    const query = params.toString();
    const url = `${window.location.pathname}${query ? `?${query}` : ''}`;
    window.history.replaceState({}, '', url);
};

const loadCalls = async () => {
    loading.value = true;
    error.value = '';
    syncFiltersToUrl();
    try {
        const { data } = await api.get('/calls', { params: { ...form } });
        calls.value = data.calls;
        totalCount.value = data.total_count ?? 0;
        operators.value = data.operators;
        statuses.value = data.statuses;
    } catch (e) {
        error.value = e.response?.data?.message || 'Zənglər yüklənmədi';
    } finally {
        loading.value = false;
    }
};

const applyFilters = () => {
    form.page = 1;
    selectedIds.value = [];
    loadCalls();
};

const resetFilters = () => {
    Object.assign(form, filterDefaults());
    selectedIds.value = [];
    loadCalls();
};

const goToPage = (url) => {
    if (!url) return;
    const pageParam = new URL(url, window.location.origin).searchParams.get('page');
    form.page = pageParam ? Number(pageParam) : 1;
    loadCalls();
};

const isSelected = (id) => selectedIds.value.includes(id);

const toggleRow = (call) => {
    if (call.status !== FAILED_STATUS) return;

    if (isSelected(call.id)) {
        selectedIds.value = selectedIds.value.filter((id) => id !== call.id);
    } else {
        selectedIds.value = [...selectedIds.value, call.id];
    }
};

const toggleSelectAllVisible = () => {
    const visibleIds = selectableCalls.value.map((call) => call.id);

    if (allVisibleSelected.value) {
        selectedIds.value = selectedIds.value.filter((id) => !visibleIds.includes(id));
        return;
    }

    selectedIds.value = [...new Set([...selectedIds.value, ...visibleIds])];
};

const clearSelection = () => {
    selectedIds.value = [];
};

const reanalyzeSelected = async () => {
    if (!selectedIds.value.length || reanalyzing.value) return;

    if (!confirm(`${selectedIds.value.length} zəngi yenidən analiz növbəsinə əlavə etmək istəyirsiniz?`)) {
        return;
    }

    reanalyzing.value = true;
    error.value = '';
    success.value = '';

    try {
        const { data } = await api.post('/calls/reanalyze', {
            call_ids: selectedIds.value,
        });
        selectedIds.value = [];
        success.value = data.message || 'Zənglər yenidən analiz növbəsinə əlavə olundu.';
        await loadCalls();
    } catch (e) {
        error.value = e.response?.data?.message || 'Yenidən analiz uğursuz oldu';
    } finally {
        reanalyzing.value = false;
    }
};

const onPopState = () => {
    readFiltersFromUrl();
    selectedIds.value = [];
    loadCalls();
};

onMounted(() => {
    readFiltersFromUrl();
    loadCalls();
    window.addEventListener('popstate', onPopState);
});

onBeforeUnmount(() => {
    window.removeEventListener('popstate', onPopState);
});
</script>

<template>
    <Head title="Zənglər" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="page-title">Zənglər</h2>
                <Link :href="route('calls.create')">
                    <PrimaryButton>Audio yüklə</PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="space-y-4">
            <div class="panel-card">
                <div class="panel-card-header">
                    <h3>Filter</h3>
                </div>
                <div class="panel-card-body space-y-3 p-4">
                <div class="grid gap-6 md:grid-cols-3 xl:grid-cols-4">
                    <TextInput v-model="form.search" type="text" class="w-full" placeholder="Axtarış..." @keyup.enter="applyFilters" />
                    <select v-model="form.status" :class="selectClass">
                        <option value="">Bütün statuslar</option>
                        <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <select v-model="form.operator_id" :class="selectClass">
                        <option value="">Bütün operatorlar</option>
                        <option v-for="op in operators" :key="op.id" :value="op.id">{{ op.name }}</option>
                    </select>
                    <TextInput v-model="form.score_min" type="number" class="w-full" placeholder="Min skor" />
                    <TextInput v-model="form.score_max" type="number" class="w-full" placeholder="Max skor" />
                    <select v-model="form.problem" :class="selectClass">
                        <option value="">Hamısı</option>
                        <option value="1">Yalnız problemli</option>
                    </select>
                    <select v-model="form.fake_scored" :class="selectClass">
                        <option value="">Hamısı (skor növü)</option>
                        <option value="real">Yalnız real skor</option>
                        <option value="fake">Yalnız fake skor</option>
                    </select>
                    <select v-model="form.transcription_provider" :class="selectClass">
                        <option value="">Transkript modeli — Hamısı</option>
                        <option value="gemini">Gemini</option>
                        <option value="elevenlabs">ElevenLabs</option>
                    </select>
                    <select v-model="form.analysis_provider" :class="selectClass">
                        <option value="">Analiz modeli — Hamısı</option>
                        <option value="gemini">Gemini</option>
                        <option value="openai">OpenAI</option>
                    </select>
                </div>
                <div class="mt-3 flex gap-2">
                    <PrimaryButton type="button" @click="applyFilters">Filter</PrimaryButton>
                    <button type="button" class="inline-flex h-10 items-center rounded-lg border border-border bg-card-inner px-4 text-sm font-medium text-foreground hover:bg-accent" @click="resetFilters">Sıfırla</button>
                </div>
                <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-left text-sm text-muted-foreground">
                        {{ calls.total ?? 0 }} / {{ totalCount }} nəticə göstərilir
                    </p>
                    <div v-if="selectedIds.length" class="flex flex-wrap items-center gap-3">
                        <span class="text-sm text-muted-foreground">{{ selectedIds.length }} zəng seçildi</span>
                        <PrimaryButton type="button" :disabled="reanalyzing" @click="reanalyzeSelected">
                            {{ reanalyzing ? 'Göndərilir...' : 'Yenidən analiz et' }}
                        </PrimaryButton>
                        <SecondaryButton type="button" :disabled="reanalyzing" @click="clearSelection">
                            Ləğv et
                        </SecondaryButton>
                    </div>
                </div>
                </div>
            </div>

            <div v-if="success" class="alert-success">{{ success }}</div>
            <div v-if="error" class="alert-error">{{ error }}</div>
            <div v-if="loading" class="py-10 text-center text-sm text-muted-foreground">Yüklənir...</div>

            <div v-else class="panel-card">
                <div class="panel-card-header">
                    <h3>Zənglər</h3>
                </div>
                <div class="panel-card-body">
                <div class="overflow-x-auto">
                    <table class="panel-table min-w-full">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input
                                        type="checkbox"
                                        class="field-checkbox"
                                        :checked="allVisibleSelected"
                                        :indeterminate.prop="someVisibleSelected"
                                        :disabled="!selectableCalls.length"
                                        @change="toggleSelectAllVisible"
                                    />
                                </th>
                                <th>Müştəri</th>
                                <th>Operator</th>
                                <th>Status</th>
                                <th>Transkript</th>
                                <th>Analiz</th>
                                <th>Müddət</th>
                                <th>Skor</th>
                                <th>Flag</th>
                                <th>Tarix</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="call in calls.data" :key="call.id">
                                <td>
                                    <input
                                        v-if="call.status === FAILED_STATUS"
                                        type="checkbox"
                                        class="field-checkbox"
                                        :checked="isSelected(call.id)"
                                        @change="toggleRow(call)"
                                    />
                                    <span v-else class="inline-block w-4" />
                                </td>
                                <td>
                                    <Link :href="route('calls.show', call.id)" class="font-medium hover:underline">
                                        {{ call.customer_name || 'Adsız müştəri' }}
                                    </Link>
                                    <div class="text-xs text-muted-foreground">{{ call.customer_phone || '—' }}</div>
                                </td>
                                <td class="text-muted-foreground">{{ call.operator || '—' }}</td>
                                <td><StatusBadge :status="call.status" /></td>
                                <td>
                                    <span
                                        v-if="providerLabel(call.transcription_provider)"
                                        class="badge-muted"
                                        :title="call.transcription_model || undefined"
                                    >
                                        {{ providerLabel(call.transcription_provider) }}
                                    </span>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                                <td>
                                    <span
                                        v-if="providerLabel(call.analysis_provider)"
                                        class="badge-muted"
                                        :title="call.analysis_model || undefined"
                                    >
                                        {{ providerLabel(call.analysis_provider) }}
                                    </span>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                                <td class="text-muted-foreground">{{ call.duration }}</td>
                                <td>
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <ScoreBadge :score="call.score" />
                                        <span
                                            v-if="call.is_fake_scored"
                                            class="badge-warn"
                                            :title="FAKE_SCORE_TOOLTIP"
                                        >
                                            Fake
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div class="flex flex-wrap gap-1">
                                        <span v-if="call.has_rudeness" class="badge-orange">Kobudluq</span>
                                        <span v-if="call.has_interruption" class="badge-warn">Söz kəsmə</span>
                                        <span v-if="!call.has_rudeness && !call.has_interruption" class="text-muted-foreground">—</span>
                                    </div>
                                </td>
                                <td class="text-muted-foreground">{{ call.called_at || call.created_at }}</td>
                                <td class="text-right">
                                    <Link :href="route('calls.show', call.id)" class="text-sm font-medium text-foreground hover:underline">
                                        Dinlə / oxu
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="!calls.data?.length" class="px-4 py-10 text-center text-sm text-muted-foreground">Zəng tapılmadı.</div>
                <div v-if="calls.links?.length > 3" class="flex flex-wrap gap-2 border-t border-border px-4 py-3">
                    <button
                        v-for="(link, index) in calls.links"
                        :key="index"
                        type="button"
                        class="rounded-lg px-3 py-1.5 text-sm font-medium disabled:opacity-40"
                        :class="link.active ? 'bg-primary text-primary-foreground' : 'border border-border bg-card-inner text-muted-foreground hover:bg-accent'"
                        :disabled="!link.url"
                        v-html="link.label"
                        @click="goToPage(link.url)"
                    />
                </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
