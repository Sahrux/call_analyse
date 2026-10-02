<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ScoreBadge from '@/Components/ScoreBadge.vue';
import TextInput from '@/Components/TextInput.vue';
import api from '@/api';
import { Head, Link } from '@inertiajs/vue3';
import { onMounted, reactive, ref } from 'vue';

const loading = ref(true);
const error = ref('');
const operators = ref({ data: [], links: [] });
const form = reactive({
    search: '',
    page: 1,
});

const loadOperators = async () => {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/operators', {
            params: { search: form.search, page: form.page },
        });
        operators.value = data.operators;
        if (data.filters?.search !== undefined) {
            form.search = data.filters.search;
        }
    } catch (e) {
        error.value = e.response?.data?.message || 'Operatorlar yüklənmədi';
    } finally {
        loading.value = false;
    }
};

const applySearch = () => {
    form.page = 1;
    loadOperators();
};

const goToPage = (url) => {
    if (!url) return;
    const pageParam = new URL(url, window.location.origin).searchParams.get('page');
    form.page = pageParam ? Number(pageParam) : 1;
    loadOperators();
};

const remove = async (id) => {
    if (!confirm('Operatoru silmək istəyirsiniz?')) return;
    error.value = '';
    try {
        await api.delete(`/operators/${id}`);
        await loadOperators();
    } catch (e) {
        error.value = e.response?.data?.message || 'Silinmə uğursuz oldu';
    }
};

onMounted(loadOperators);
</script>

<template>
    <Head title="Operatorlar" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="page-title">Operatorlar</h2>
                <Link :href="route('operators.create')"><PrimaryButton>Yeni operator</PrimaryButton></Link>
            </div>
        </template>

        <div class="space-y-4">
            <div class="panel-card">
                <div class="panel-card-header">
                    <h3>Axtarış</h3>
                </div>
                <div class="panel-card-body flex gap-2 p-4">
                    <TextInput v-model="form.search" type="text" class="w-full" placeholder="Ad, kod, email..." @keyup.enter="applySearch" />
                    <PrimaryButton type="button" @click="applySearch">Axtar</PrimaryButton>
                </div>
            </div>

            <div v-if="error" class="alert-error">{{ error }}</div>
            <div v-if="loading" class="py-10 text-center text-sm text-muted-foreground">Yüklənir...</div>

            <div v-else class="panel-card">
                <div class="panel-card-header">
                    <h3>Operatorlar</h3>
                </div>
                <div class="panel-card-body">
                <div class="overflow-x-auto">
                    <table class="panel-table min-w-full">
                        <thead>
                            <tr>
                                <th>Ad</th>
                                <th>Kod</th>
                                <th>Zəng sayı</th>
                                <th>Orta skor</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="op in operators.data" :key="op.id">
                                <td class="font-medium">{{ op.name }}</td>
                                <td class="text-muted-foreground">{{ op.employee_code }}</td>
                                <td class="text-muted-foreground">{{ op.calls_count }}</td>
                                <td><ScoreBadge :score="op.avg_score" /></td>
                                <td>
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium" :class="op.is_active ? 'tone-success' : 'tone-muted'">
                                        {{ op.is_active ? 'Aktiv' : 'Deaktiv' }}
                                    </span>
                                </td>
                                <td class="text-right">
                                    <Link :href="route('operators.edit', op.id)" class="mr-3 text-foreground hover:underline">Edit</Link>
                                    <button type="button" class="text-danger hover:underline" @click="remove(op.id)">Sil</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="!operators.data?.length" class="px-4 py-10 text-center text-sm text-muted-foreground">Operator yoxdur.</div>
                <div v-if="operators.links?.length > 3" class="flex flex-wrap gap-2 border-t border-border px-4 py-3">
                    <button
                        v-for="(link, index) in operators.links"
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
