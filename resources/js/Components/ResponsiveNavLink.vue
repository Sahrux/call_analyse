<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    active: {
        type: Boolean,
    },
});

const classes = computed(() =>
    props.active
        ? 'block w-full border-l-4 border-foreground bg-accent py-2 pe-4 ps-3 text-start text-sm font-medium text-foreground transition duration-150 ease-in-out focus:outline-none'
        : 'block w-full border-l-4 border-transparent py-2 pe-4 ps-3 text-start text-sm font-medium text-muted-foreground transition duration-150 ease-in-out hover:border-border hover:bg-accent hover:text-foreground focus:outline-none',
);
</script>

<template>
    <Link :href="href" :class="classes">
        <slot />
    </Link>
</template>
