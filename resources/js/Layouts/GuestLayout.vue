<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center bg-app px-4 py-10 text-foreground">
        <Link href="/" class="mb-8 flex items-center gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary text-sm font-semibold text-primary-foreground">CA</span>
            <span class="text-xl font-semibold text-foreground">Call Analyse</span>
        </Link>

        <div class="w-full max-w-md rounded-2xl border border-border bg-card px-6 py-6">
            <slot />
        </div>
    </div>
</template>
