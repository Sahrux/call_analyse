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
    userId: { type: [Number, String], default: null },
});

const isEdit = computed(() => Boolean(props.userId));
const loading = ref(true);
const error = ref('');
const processing = ref(false);
const roles = ref({});

const form = reactive({
    name: '',
    email: '',
    role: 'supervisor',
    password: '',
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

const loadPage = async () => {
    loading.value = true;
    error.value = '';
    try {
        if (props.userId) {
            const { data } = await api.get(`/users/${props.userId}`);
            roles.value = data.roles || {};
            form.name = data.user.name || '';
            form.email = data.user.email || '';
            form.role = data.user.role || 'supervisor';
            form.is_active = data.user.is_active ?? true;
            form.password = '';
        } else {
            const { data } = await api.get('/users', { params: { per_page: 1 } });
            roles.value = data.roles || {};
        }
    } catch (e) {
        error.value = e.response?.data?.message || 'Məlumat yüklənmədi';
    } finally {
        loading.value = false;
    }
};

const submit = async () => {
    processing.value = true;
    error.value = '';
    clearErrors();

    const payload = {
        name: form.name,
        email: form.email,
        role: form.role,
        is_active: form.is_active,
    };

    if (!isEdit.value || form.password) {
        payload.password = form.password;
    }

    try {
        if (isEdit.value) {
            await api.put(`/users/${props.userId}`, payload);
        } else {
            await api.post('/users', payload);
        }
        router.visit(route('users.index'));
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

onMounted(loadPage);

const selectClass = 'field-select mt-1 w-full';
</script>

<template>
    <Head :title="isEdit ? 'İstifadəçi redaktə' : 'Yeni istifadəçi'" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('users.index')" class="text-sm text-foreground hover:underline">← İstifadəçilər</Link>
                <h2 class="page-title">
                    {{ isEdit ? 'İstifadəçi redaktə' : 'Yeni istifadəçi' }}
                </h2>
            </div>
        </template>

        <div v-if="loading" class="py-10 text-center text-sm text-muted-foreground">Yüklənir...</div>
        <div v-else-if="error && isEdit && !form.name" class="alert-error">{{ error }}</div>

        <form v-else class="panel-card max-w-2xl space-y-5 p-6" @submit.prevent="submit">
            <div v-if="error" class="alert-error">{{ error }}</div>

            <div>
                <InputLabel for="name" value="Ad" />
                <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full" required />
                <InputError class="mt-2" :message="errors.name" />
            </div>
            <div>
                <InputLabel for="email" value="Email" />
                <TextInput id="email" v-model="form.email" type="email" class="mt-1 block w-full" required />
                <InputError class="mt-2" :message="errors.email" />
            </div>
            <div>
                <InputLabel value="Rol" />
                <select v-model="form.role" :class="selectClass">
                    <option v-for="(label, key) in roles" :key="key" :value="key">{{ label }}</option>
                </select>
                <InputError class="mt-2" :message="errors.role" />
            </div>
            <div>
                <InputLabel for="password" :value="isEdit ? 'Yeni şifrə (boş buraxıla bilər)' : 'Şifrə'" />
                <TextInput id="password" v-model="form.password" type="password" class="mt-1 block w-full" :required="!isEdit" />
                <InputError class="mt-2" :message="errors.password" />
            </div>
            <label class="flex items-center gap-2 text-sm text-foreground">
                <Checkbox v-model:checked="form.is_active" />
                Aktivdir
            </label>
            <div class="flex items-center gap-3">
                <PrimaryButton :disabled="processing">Saxla</PrimaryButton>
                <Link :href="route('users.index')" class="text-sm text-muted-foreground hover:text-foreground">Ləğv et</Link>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
