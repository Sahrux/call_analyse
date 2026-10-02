<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import api from '@/api';
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted, reactive, ref } from 'vue';

const operators = ref([]);
const loading = ref(true);
const error = ref('');
const processing = ref(false);

const form = reactive({
    operator_id: '',
    customer_name: '',
    customer_phone: '',
    language: 'az',
    called_at: '',
    audio: null,
});

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

const loadOperators = async () => {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/operators', { params: { per_page: 100 } });
        operators.value = data.operators?.data ?? data.operators ?? [];
    } catch (e) {
        error.value = e.response?.data?.message || 'Operatorlar yüklənmədi';
    } finally {
        loading.value = false;
    }
};

const submit = async () => {
    processing.value = true;
    error.value = '';
    clearErrors();

    const formData = new FormData();
    if (form.operator_id) formData.append('operator_id', form.operator_id);
    if (form.customer_name) formData.append('customer_name', form.customer_name);
    if (form.customer_phone) formData.append('customer_phone', form.customer_phone);
    formData.append('language', form.language);
    if (form.called_at) formData.append('called_at', form.called_at);
    if (form.audio) formData.append('audio', form.audio);

    try {
        await api.post('/calls', formData);
        router.visit(route('calls.index'));
    } catch (e) {
        if (e.response?.status === 422 && e.response.data?.errors) {
            mapErrors(e.response.data.errors);
        } else {
            error.value = e.response?.data?.message || 'Yükləmə uğursuz oldu';
        }
    } finally {
        processing.value = false;
    }
};

onMounted(loadOperators);

const selectClass = 'field-select mt-1 w-full';
</script>

<template>
    <Head title="Audio yüklə" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('calls.index')" class="text-sm text-foreground hover:underline">← Zənglər</Link>
                <h2 class="page-title">Audio yüklə</h2>
            </div>
        </template>

        <div v-if="loading" class="py-10 text-center text-sm text-muted-foreground">Yüklənir...</div>
        <div v-else-if="error && !operators.length" class="alert-error">{{ error }}</div>

        <form v-else class="panel-card max-w-2xl space-y-5 p-6" @submit.prevent="submit">
            <div v-if="error" class="alert-error">{{ error }}</div>

            <div>
                <InputLabel value="Operator" />
                <select v-model="form.operator_id" :class="selectClass">
                    <option value="">Seçin</option>
                    <option v-for="op in operators" :key="op.id" :value="op.id">{{ op.name }}</option>
                </select>
                <InputError class="mt-2" :message="errors.operator_id" />
            </div>

            <div>
                <InputLabel for="customer_name" value="Müştəri adı" />
                <TextInput id="customer_name" v-model="form.customer_name" type="text" class="mt-1 block w-full" />
                <InputError class="mt-2" :message="errors.customer_name" />
            </div>

            <div>
                <InputLabel for="customer_phone" value="Müştəri telefonu" />
                <TextInput id="customer_phone" v-model="form.customer_phone" type="text" class="mt-1 block w-full" />
                <InputError class="mt-2" :message="errors.customer_phone" />
            </div>

            <div>
                <InputLabel value="Dil" />
                <select v-model="form.language" :class="selectClass">
                    <option value="az">Azərbaycan</option>
                    <option value="ru">Rus</option>
                    <option value="en">İngilis</option>
                </select>
                <InputError class="mt-2" :message="errors.language" />
            </div>

            <div>
                <InputLabel for="called_at" value="Zəng tarixi" />
                <TextInput id="called_at" v-model="form.called_at" type="datetime-local" class="mt-1 block w-full" />
                <InputError class="mt-2" :message="errors.called_at" />
            </div>

            <div>
                <InputLabel for="audio" value="Audio fayl (mp3, wav, m4a)" />
                <input
                    id="audio"
                    type="file"
                    accept=".mp3,.wav,.m4a,.ogg,.webm,audio/*"
                    class="mt-1 block w-full text-sm text-muted-foreground file:mr-4 file:h-9 file:rounded-lg file:border file:border-border file:bg-card-inner file:px-4 file:py-2 file:text-sm file:font-medium file:text-foreground hover:file:bg-accent"
                    @input="form.audio = $event.target.files[0]"
                />
                <InputError class="mt-2" :message="errors.audio" />
            </div>

            <div class="flex items-center gap-3">
                <PrimaryButton :disabled="processing">Yüklə</PrimaryButton>
                <Link :href="route('calls.index')" class="text-sm text-muted-foreground hover:text-foreground">Ləğv et</Link>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
