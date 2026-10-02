<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import api from '@/api';
import { Head } from '@inertiajs/vue3';
import { onMounted, reactive, ref } from 'vue';

const loading = ref(true);
const error = ref('');
const success = ref('');
const processing = ref(false);

const form = reactive({
    weight_courtesy: 25,
    weight_operator_interruption: 10,
    weight_customer_interruption: 10,
    weight_rudeness: 15,
    weight_emotion: 10,
    weight_operator_inappropriate: 7,
    weight_customer_inappropriate: 8,
    score_gamma: 1.5,
    alert_score_threshold: 40,
    default_language: 'az',
    audio_retention_days: 90,
    transcription_provider: 'gemini',
    analysis_provider: 'gemini',
});

const scoringParameters = [
    {
        key: 'weight_courtesy',
        label: 'Nəzakət',
        hint: 'Keyfiyyət skorunun ümumi bazadakı çəkisi.',
        min: 0,
        max: 100,
    },
    {
        key: 'weight_operator_interruption',
        label: 'Operator sözkəsmə',
        hint: 'Hər hadisə ümumi skoru bu faiz qədər vurma yolu ilə azaldır (məs. 10 = hər hadisə skoru 0.9 dəfə azaldır).',
        min: 0,
        max: 100,
    },
    {
        key: 'weight_customer_interruption',
        label: 'Müştəri sözkəsmə',
        hint: 'Hər hadisə ümumi skoru bu faiz qədər vurma yolu ilə azaldır (məs. 10 = hər hadisə skoru 0.9 dəfə azaldır).',
        min: 0,
        max: 100,
    },
    {
        key: 'weight_rudeness',
        label: 'Kobudluq',
        hint: 'Keyfiyyət skorunun ümumi bazadakı çəkisi.',
        min: 0,
        max: 100,
    },
    {
        key: 'weight_emotion',
        label: 'Emosional ton',
        hint: 'Keyfiyyət skorunun ümumi bazadakı çəkisi.',
        min: 0,
        max: 100,
    },
    {
        key: 'weight_operator_inappropriate',
        label: 'Operator uyğunsuz ifadələr',
        hint: 'Hər hadisə ümumi skoru bu faiz qədər vurma yolu ilə azaldır (məs. 10 = hər hadisə skoru 0.9 dəfə azaldır).',
        min: 0,
        max: 100,
    },
    {
        key: 'weight_customer_inappropriate',
        label: 'Müştəri uyğunsuz ifadələr',
        hint: 'Hər hadisə ümumi skoru bu faiz qədər vurma yolu ilə azaldır (məs. 10 = hər hadisə skoru 0.9 dəfə azaldır).',
        min: 0,
        max: 100,
    },
];

const transcriptionOptions = [
    { value: 'gemini', label: 'Gemini' },
    { value: 'elevenlabs', label: 'ElevenLabs' },
];

const analysisOptions = [
    { value: 'gemini', label: 'Gemini' },
    { value: 'openai', label: 'OpenAI' },
];

const errors = reactive({});

const clearErrors = () => {
    Object.keys(errors).forEach((key) => delete errors[key]);
};

const mapErrors = (responseErrors = {}) => {
    clearErrors();
    Object.entries(responseErrors).forEach(([key, messages]) => {
        errors[key] = Array.isArray(messages) ? messages[0] : messages;
    });
};

const loadSettings = async () => {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/settings');
        Object.assign(form, data.settings);
    } catch (e) {
        error.value = e.response?.data?.message || 'Ayarlar yüklənmədi';
    } finally {
        loading.value = false;
    }
};

const submit = async () => {
    form.score_gamma = Math.round(Number(form.score_gamma) * 10) / 10;
    processing.value = true;
    error.value = '';
    success.value = '';
    clearErrors();

    try {
        const { data } = await api.put('/settings', { ...form });
        success.value = data.message || 'Ayarlar saxlanıldı.';
    } catch (e) {
        if (e.response?.status === 422 && e.response.data?.errors) {
            mapErrors(e.response.data.errors);
            error.value = e.response.data?.message || '';
        } else {
            error.value = e.response?.data?.message || 'Saxlama uğursuz oldu';
        }
    } finally {
        processing.value = false;
    }
};

onMounted(loadSettings);

const selectClass = 'field-select mt-1 w-full';
</script>

<template>
    <Head title="Ayarlar" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="page-title">Ayarlar</h2>
        </template>

        <div v-if="loading" class="py-10 text-center text-sm text-muted-foreground">Yüklənir...</div>
        <div v-else-if="error && !form.default_language" class="alert-error">{{ error }}</div>

        <form v-else class="max-w-3xl space-y-6" @submit.prevent="submit">
            <div class="panel-card space-y-6 p-6">
            <div v-if="error" class="alert-error">{{ error }}</div>
            <div v-if="success" class="alert-success">{{ success }}</div>

            <div>
                <h3 class="mb-1 font-semibold">Skor çəkiləri</h3>
                <p class="mb-3 text-sm text-muted-foreground">
                    Keyfiyyət göstəriciləri (Nəzakət, Kobudluq, Emosional ton) ümumi bazanın çəkili ortasını təşkil edir. Sözkəsmə və uyğunsuz ifadələr hər hadisə üçün ümumi skoru faizlə azaldır (hadisələr üst-üstə yığılır, skor heç vaxt qəfil 0 olmur). Cəmi 100 olmaq məcburi deyil.
                </p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div v-for="field in scoringParameters" :key="field.key">
                        <InputLabel :value="field.label" />
                        <TextInput
                            v-model="form[field.key]"
                            type="number"
                            :min="field.min"
                            :max="field.max"
                            class="mt-1 block w-full"
                        />
                        <p class="mt-1 text-xs text-muted-foreground">{{ field.hint }}</p>
                        <InputError class="mt-2" :message="errors[field.key]" />
                    </div>
                </div>
                <div class="mt-4 max-w-sm">
                    <InputLabel value="Sərtlik əmsalı (gamma)" />
                    <TextInput
                        v-model="form.score_gamma"
                        type="number"
                        min="1"
                        max="3"
                        step="0.1"
                        class="mt-1 block w-full"
                    />
                    <p class="mt-1 text-xs text-muted-foreground">1.0 = xətti hesablama. Dəyər artdıqca ümumi skor daha sərt hesablanır.</p>
                    <InputError class="mt-2" :message="errors.score_gamma" />
                </div>
            </div>

            <div class="grid gap-4 border-t border-border pt-6 sm:grid-cols-2">
                <div>
                    <InputLabel value="Alert skor eşiyi" />
                    <TextInput v-model="form.alert_score_threshold" type="number" min="0" max="100" class="mt-1 block w-full" />
                    <InputError class="mt-2" :message="errors.alert_score_threshold" />
                </div>
                <div>
                    <InputLabel value="Audio saxlama (gün)" />
                    <TextInput v-model="form.audio_retention_days" type="number" min="1" class="mt-1 block w-full" />
                    <InputError class="mt-2" :message="errors.audio_retention_days" />
                </div>
                <div>
                    <InputLabel value="Default dil" />
                    <select v-model="form.default_language" :class="selectClass">
                        <option value="az">Azərbaycan</option>
                        <option value="ru">Rus</option>
                        <option value="en">İngilis</option>
                    </select>
                    <InputError class="mt-2" :message="errors.default_language" />
                </div>
            </div>
            </div>

            <div class="panel-card space-y-6 p-6">
                <div>
                    <h3 class="mb-1 font-semibold">AI provayderləri</h3>
                    <p class="text-sm text-muted-foreground">Transkript və analiz üçün istifadə olunan modellər.</p>
                </div>

                <div>
                    <InputLabel value="Transkript modeli" />
                    <div class="mt-2 grid gap-3 sm:grid-cols-2">
                        <label
                            v-for="option in transcriptionOptions"
                            :key="option.value"
                            class="flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3"
                            :class="form.transcription_provider === option.value ? 'border-foreground bg-accent' : 'border-border bg-card-inner'"
                        >
                            <input
                                v-model="form.transcription_provider"
                                type="radio"
                                class="accent-foreground"
                                name="transcription_provider"
                                :value="option.value"
                            />
                            <span class="text-sm font-medium text-foreground">{{ option.label }}</span>
                        </label>
                    </div>
                    <p class="mt-2 text-xs text-muted-foreground">Zəng səsi mətnə çevrilərkən istifadə olunur</p>
                    <InputError class="mt-2" :message="errors.transcription_provider" />
                </div>

                <div>
                    <InputLabel value="Analiz modeli" />
                    <div class="mt-2 grid gap-3 sm:grid-cols-2">
                        <label
                            v-for="option in analysisOptions"
                            :key="option.value"
                            class="flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3"
                            :class="form.analysis_provider === option.value ? 'border-foreground bg-accent' : 'border-border bg-card-inner'"
                        >
                            <input
                                v-model="form.analysis_provider"
                                type="radio"
                                class="accent-foreground"
                                name="analysis_provider"
                                :value="option.value"
                            />
                            <span class="text-sm font-medium text-foreground">{{ option.label }}</span>
                        </label>
                    </div>
                    <p class="mt-2 text-xs text-muted-foreground">Skorlama və uyğunsuz ifadələrin təyini üçün istifadə olunur</p>
                    <InputError class="mt-2" :message="errors.analysis_provider" />
                </div>

                <PrimaryButton :disabled="processing">Saxla</PrimaryButton>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
