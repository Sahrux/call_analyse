<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import api from '@/api';
import { Head, Link } from '@inertiajs/vue3';
import { onMounted, reactive, ref } from 'vue';

const loading = ref(true);
const error = ref('');
const users = ref({ data: [], links: [] });
const form = reactive({
    search: '',
    page: 1,
});

const loadUsers = async () => {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/users', {
            params: { search: form.search, page: form.page },
        });
        users.value = data.users;
        if (data.filters?.search !== undefined) {
            form.search = data.filters.search;
        }
    } catch (e) {
        error.value = e.response?.data?.message || 'İstifadəçilər yüklənmədi';
    } finally {
        loading.value = false;
    }
};

const applySearch = () => {
    form.page = 1;
    loadUsers();
};

const goToPage = (url) => {
    if (!url) return;
    const pageParam = new URL(url, window.location.origin).searchParams.get('page');
    form.page = pageParam ? Number(pageParam) : 1;
    loadUsers();
};

const remove = async (id) => {
    if (!confirm('İstifadəçini silmək istəyirsiniz?')) return;
    error.value = '';
    try {
        await api.delete(`/users/${id}`);
        await loadUsers();
    } catch (e) {
        error.value = e.response?.data?.message || 'Silinmə uğursuz oldu';
    }
};

onMounted(loadUsers);
</script>

<template>
    <Head title="İstifadəçilər" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="page-title">İstifadəçilər</h2>
                <Link :href="route('users.create')"><PrimaryButton>Yeni istifadəçi</PrimaryButton></Link>
            </div>
        </template>

        <div class="space-y-4">
            <div class="panel-card">
                <div class="panel-card-header">
                    <h3>Axtarış</h3>
                </div>
                <div class="panel-card-body flex gap-2 p-4">
                    <TextInput v-model="form.search" type="text" class="w-full" placeholder="Ad və ya email..." @keyup.enter="applySearch" />
                    <PrimaryButton type="button" @click="applySearch">Axtar</PrimaryButton>
                </div>
            </div>

            <div v-if="error" class="alert-error">{{ error }}</div>
            <div v-if="loading" class="py-10 text-center text-sm text-muted-foreground">Yüklənir...</div>

            <div v-else class="panel-card">
                <div class="panel-card-header">
                    <h3>İstifadəçilər</h3>
                </div>
                <div class="panel-card-body">
                <div class="overflow-x-auto">
                    <table class="panel-table min-w-full">
                        <thead>
                            <tr>
                                <th>Ad</th>
                                <th>Email</th>
                                <th>Rol</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="user in users.data" :key="user.id">
                                <td class="font-medium">{{ user.name }}</td>
                                <td class="text-muted-foreground">{{ user.email }}</td>
                                <td class="text-muted-foreground">{{ user.role_label }}</td>
                                <td>
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium" :class="user.is_active ? 'tone-success' : 'tone-muted'">
                                        {{ user.is_active ? 'Aktiv' : 'Deaktiv' }}
                                    </span>
                                </td>
                                <td class="text-right">
                                    <Link :href="route('users.edit', user.id)" class="mr-3 text-foreground hover:underline">Edit</Link>
                                    <button type="button" class="text-danger hover:underline" @click="remove(user.id)">Sil</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="!users.data?.length" class="px-4 py-10 text-center text-sm text-muted-foreground">İstifadəçi yoxdur.</div>
                <div v-if="users.links?.length > 3" class="flex flex-wrap gap-2 border-t border-border px-4 py-3">
                    <button
                        v-for="(link, index) in users.links"
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
