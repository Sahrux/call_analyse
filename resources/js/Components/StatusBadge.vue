<script setup>
defineProps({
    status: {
        type: String,
        required: true,
    },
});

const labels = {
    pending: 'Gözləyir',
    processing: 'İşlənir',
    completed: 'Hazır',
    failed: 'Xəta',
};

const tones = {
    pending: 'tone-muted',
    processing: 'tone-info',
    completed: 'tone-success',
    failed: 'tone-danger',
};
</script>

<template>
    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium" :class="tones[status] || 'tone-muted'">
        {{ labels[status] || status }}
    </span>
</template>
