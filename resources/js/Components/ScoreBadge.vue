<script setup>
const props = defineProps({
    score: {
        type: [Number, String],
        default: null,
    },
    suffix: {
        type: String,
        default: '',
    },
    inverse: {
        type: Boolean,
        default: false,
    },
});

const tone = (score) => {
    if (score === null || score === undefined || score === '') {
        return 'tone-muted';
    }

    const value = Number(score);
    const positive = props.inverse
        ? value < 60
        : value >= 80;
    const caution = value >= 60 && value < 80;

    if (positive) {
        return 'tone-success';
    }

    if (caution) {
        return 'tone-warn';
    }

    return 'tone-danger';
};
</script>

<template>
    <span class="inline-flex min-w-12 items-center justify-center rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="tone(score)">
        {{ score === null || score === undefined || score === '' ? '—' : `${score}${suffix}` }}
    </span>
</template>
