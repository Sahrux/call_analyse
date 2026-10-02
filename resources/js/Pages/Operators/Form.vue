<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import api from '@/api';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref } from 'vue';

const props = defineProps({
    operatorId: { type: [Number, String], default: null },
});

const isEdit = computed(() => Boolean(props.operatorId));
const loading = ref(Boolean(props.operatorId));
const error = ref('');
const processing = ref(false);

const form = reactive({
    name: '',
    employee_code: '',
    email: '',
    phone: '',
    is_active: true,
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

const loadOperator = async () => {
    if (!props.operatorId) {
        loading.value = false;
        return;
    }

    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get(`/operators/${props.operatorId}`);
        const op = data.operator;
        form.name = op.name || '';
        form.employee_code = op.employee_code || '';
        form.email = op.email || '';
        form.phone = op.phone || '';
        form.is_active = op.is_active ?? true;
    } catch (e) {
        error.value = e.response?.data?.message || 'Operator yüklənmədi';
    } finally {
        loading.value = false;
    }
};

const submit = async () => {
    processing.value = true;
    error.value = '';
    clearErrors();

    try {
        if (isEdit.value) {
            await api.put(`/operators/${props.operatorId}`, { ...form });
        } else {
            await api.post('/operators', { ...form });
        }
        router.visit(route('operators.index'));
    } catch (e) {
        if (e.response?.status === 422 && e.response.data?.errors) {
            mapErrors(e.response.data.errors);
        } else {
            error.value = e.response?.data?.message || 'Saxlama uğursuz oldu';
        }
    } finally {
        processing.value = false;
    }
};

onMounted(loadOperator);
</script>

<template>
    <Head :title="isEdit ? 'Operator redaktə' : 'Yeni operator'" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('operators.index')" class="text-sm text-foreground hover:underline">← Operatorlar</Link>
                <h2 class="page-title">
                    {{ isEdit ? 'Operator redaktə' : 'Yeni operator' }}
                </h2>
            </div>
        </template>

        <div v-if="loading" class="py-10 text-center text-sm text-muted-foreground">Yüklənir...</div>
        <div v-else-if="error && isEdit && !form.name" class="alert-error">{{ error }}</div>

        <form v-else class="panel-card max-w-2xl space-y-5 p-6" @submit.prevent="submit">
            <div v-if="error" class="alert-error">{{ error }}</div>

            <div>
                <InputLabel for="name" value="Ad soyad" />
                <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full" required />
                <InputError class="mt-2" :message="errors.name" />
            </div>
            <div>
                <InputLabel for="employee_code" value="İşçi kodu" />
                <TextInput id="employee_code" v-model="form.employee_code" type="text" class="mt-1 block w-full" required />
                <InputError class="mt-2" :message="errors.employee_code" />
            </div>
            <div>
                <InputLabel for="email" value="Email" />
                <TextInput id="email" v-model="form.email" type="email" class="mt-1 block w-full" />
                <InputError class="mt-2" :message="errors.email" />
            </div>
            <div>
                <InputLabel for="phone" value="Telefon" />
                <TextInput id="phone" v-model="form.phone" type="text" class="mt-1 block w-full" />
                <InputError class="mt-2" :message="errors.phone" />
            </div>
            <label class="flex items-center gap-2 text-sm text-foreground">
                <Checkbox v-model:checked="form.is_active" />
                Aktivdir
            </label>
            <div class="flex items-center gap-3">
                <PrimaryButton :disabled="processing">Saxla</PrimaryButton>
                <Link :href="route('operators.index')" class="text-sm text-muted-foreground hover:text-foreground">Ləğv et</Link>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
