<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ScoreBadge from '@/Components/ScoreBadge.vue';
import api from '@/api';
import { Head, Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

const loading = ref(true);
const error = ref('');
const alerts = ref({ data: [], links: [] });
const threshold = ref(40);

const loadAlerts = async () => {
    loading.value = true;
    error.value = '';
    try {
        const { data } = await api.get('/alerts');
        alerts.value = data.alerts;
        threshold.value = data.threshold;
    } catch (e) {
        error.value = e.response?.data?.message || 'Xəbərdarlıqlar yüklənmədi';
    } finally {
        loading.value = false;
    }
};

onMounted(loadAlerts);
</script>

<template>
    <Head title="Xəbərdarlıqlar" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="page-title">Xəbərdarlıqlar</h2>
                <p class="mt-1 text-sm text-muted-foreground">Skor &lt; {{ threshold }}, kobudluq və yüksək alıcı potensialı</p>
            </div>
        </template>

        <div v-if="error" class="mb-4 alert-error">{{ error }}</div>
        <div v-if="loading" class="py-10 text-center text-sm text-muted-foreground">Yüklənir...</div>

        <div v-else class="panel-card">
            <div class="panel-card-header">
                <h3>Xəbərdarlıqlar</h3>
            </div>
            <div class="panel-card-body">
            <div class="overflow-x-auto">
                <table class="panel-table min-w-full">
                    <thead>
                        <tr>
                            <th>Zəng</th>
                            <th>Operator</th>
                            <th>Skor</th>
                            <th>Səbəblər</th>
                            <th>Tarix</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="alert in alerts.data" :key="alert.id">
                            <td>
                                <Link :href="route('calls.show', alert.id)" class="font-medium hover:underline">
                                    {{ alert.customer_name || alert.customer_phone || `#${alert.id}` }}
                                </Link>
                            </td>
                            <td class="text-muted-foreground">{{ alert.operator || '—' }}</td>
                            <td><ScoreBadge :score="alert.score" /></td>
                            <td>
                                <div class="flex flex-wrap gap-1">
                                    <span v-for="reason in alert.reasons" :key="reason" class="badge-danger">
                                        {{ reason }}
                                    </span>
                                </div>
                            </td>
                            <td class="text-muted-foreground">{{ alert.called_at || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="!alerts.data?.length" class="px-4 py-10 text-center text-sm text-muted-foreground">Xəbərdarlıq yoxdur.</div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
