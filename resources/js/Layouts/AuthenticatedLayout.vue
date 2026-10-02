<script setup>
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { useTheme } from '@/composables/useTheme';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const { isDark, toggleTheme } = useTheme();

const sidebarOpen = ref(false);
const sidebarCollapsed = ref(false);
const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);
const flashError = computed(() => page.props.flash?.error);
const showFlash = ref(false);
const userName = computed(() => page.props.auth?.user?.name || 'User');
const userInitial = computed(() => userName.value.charAt(0).toUpperCase());

watch(
    () => [flashSuccess.value, flashError.value],
    ([success, error]) => {
        showFlash.value = Boolean(success || error);
        if (showFlash.value) {
            setTimeout(() => {
                showFlash.value = false;
            }, 4000);
        }
    },
    { immediate: true },
);

const navItems = [
    { label: 'Dashboard', route: 'dashboard', match: 'dashboard', icon: 'dashboard' },
    { label: 'Zənglər', route: 'calls.index', match: 'calls.*', icon: 'calls' },
    { label: 'Operatorlar', route: 'operators.index', match: 'operators.*', icon: 'operators' },
    { label: 'Xəbərdarlıqlar', route: 'alerts.index', match: 'alerts.*', icon: 'alerts' },
    { label: 'İstifadəçilər', route: 'users.index', match: 'users.*', icon: 'users' },
    { label: 'Ayarlar', route: 'settings.edit', match: 'settings.*', icon: 'settings' },
];

const isActive = (match) => route().current(match);

const searchQuery = ref('');
const searchInput = ref(null);

const azLetterMap = {
    ə: 'e',
    ı: 'i',
    ö: 'o',
    ü: 'u',
    ç: 'c',
    ş: 's',
    ğ: 'g',
};

const normalizeAz = (value) => value
    .trim()
    .toLocaleLowerCase('az')
    .replace(/[əıöüçşğ]/g, (letter) => azLetterMap[letter]);

const labelMatches = (label, query) => normalizeAz(label).includes(query);

const filteredNavItems = computed(() => {
    const query = normalizeAz(searchQuery.value);

    if (!query) {
        return navItems;
    }

    return navItems.flatMap((item) => {
        const children = item.children ?? [];
        const parentMatches = labelMatches(item.label, query);
        const matchingChildren = children.filter((child) => labelMatches(child.label, query));

        if (!parentMatches && matchingChildren.length === 0) {
            return [];
        }

        return [{
            ...item,
            children: parentMatches ? children : matchingChildren,
        }];
    });
});

const clearSearch = () => {
    searchQuery.value = '';
};

const onSearchEscape = () => {
    clearSearch();
    searchInput.value?.blur();
};

const firstVisibleMatch = computed(() => {
    const item = filteredNavItems.value[0];

    if (!item) {
        return null;
    }

    const query = normalizeAz(searchQuery.value);

    if (query && !labelMatches(item.label, query) && item.children?.length) {
        return item.children[0];
    }

    return item;
});

const goToFirstMatch = () => {
    const match = firstVisibleMatch.value;

    if (!match?.route) {
        return;
    }

    router.visit(route(match.route));
    sidebarOpen.value = false;
};

const onSearchShortcut = (event) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        searchInput.value?.focus();
    }
};

onMounted(() => {
    window.addEventListener('keydown', onSearchShortcut);
});

onUnmounted(() => {
    window.removeEventListener('keydown', onSearchShortcut);
});

const toggleSidebar = () => {
    if (window.matchMedia('(min-width: 1024px)').matches) {
        sidebarCollapsed.value = !sidebarCollapsed.value;

        return;
    }

    sidebarOpen.value = !sidebarOpen.value;
};
</script>

<template>
    <div class="min-h-screen bg-app text-foreground">
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-40 bg-[rgba(12,12,14,0.45)] lg:hidden"
            @click="sidebarOpen = false"
        />

        <aside
            class="fixed inset-y-0 left-0 z-50 flex w-[280px] flex-col bg-sidebar px-4 py-5 transition-transform duration-200"
            :class="[
                sidebarOpen ? 'translate-x-0' : '-translate-x-full',
                sidebarCollapsed ? 'lg:-translate-x-full' : 'lg:translate-x-0',
            ]"
        >
            <Link :href="route('dashboard')" class="mb-4 flex items-center gap-2.5 px-2">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary text-xs font-semibold text-primary-foreground">CA</span>
                <span class="text-[15px] font-semibold tracking-tight text-foreground">Call Analyse</span>
            </Link>

            <label class="mb-5 flex h-10 items-center gap-2 rounded-xl border border-input-border bg-input-bg px-3 text-muted-foreground">
                <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z" />
                </svg>
                <input
                    ref="searchInput"
                    v-model="searchQuery"
                    type="search"
                    placeholder="Search..."
                    aria-label="Search"
                    class="h-full w-full border-0 bg-transparent p-0 text-sm text-foreground shadow-none outline-none ring-0 placeholder:text-muted-foreground focus:ring-0 [&::-webkit-search-cancel-button]:hidden"
                    @keydown.escape.prevent="onSearchEscape"
                    @keydown.enter.prevent="goToFirstMatch"
                />
                <button
                    v-if="searchQuery"
                    type="button"
                    class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                    aria-label="Təmizlə"
                    @mousedown.prevent
                    @click.stop="clearSearch"
                >
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18" />
                    </svg>
                </button>
                <kbd v-else class="shrink-0 rounded-md border border-border bg-card-header px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground">⌘K</kbd>
            </label>

            <p v-if="filteredNavItems.length" class="mb-2 px-3 text-xs font-medium text-muted-foreground">Menyu</p>

            <nav class="flex flex-1 flex-col gap-1">
                <p v-if="normalizeAz(searchQuery) && !filteredNavItems.length" class="px-3 text-sm text-muted-foreground">Nəticə tapılmadı</p>
                <template v-for="item in filteredNavItems" :key="item.route">
                <Link
                    :href="route(item.route)"
                    class="flex h-10 items-center gap-3 rounded-lg px-3 text-sm transition"
                    :class="isActive(item.match) ? 'bg-accent font-medium text-foreground' : 'font-normal text-muted-foreground hover:bg-accent hover:text-foreground'"
                    @click="sidebarOpen = false"
                >
                    <svg v-if="item.icon === 'dashboard'" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0h4" />
                    </svg>
                    <svg v-else-if="item.icon === 'calls'" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.3a1 1 0 01.95.68l1.2 3.6a1 1 0 01-.5 1.2l-2.1 1.05a11 11 0 005.2 5.2l1.05-2.1a1 1 0 011.2-.5l3.6 1.2a1 1 0 01.68.95V19a2 2 0 01-2 2h-1C9.7 21 3 14.3 3 6V5z" />
                    </svg>
                    <svg v-else-if="item.icon === 'operators'" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m6-4a4 4 0 11-8 0 4 4 0 018 0zm6 0a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <svg v-else-if="item.icon === 'alerts'" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0a3 3 0 11-6 0" />
                    </svg>
                    <svg v-else-if="item.icon === 'users'" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                    <svg v-else class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.3 4.3l.4-2.3h2.6l.4 2.3a7.9 7.9 0 012.1.9l2.1-1.1 1.8 1.8-1.1 2.1c.4.7.7 1.4.9 2.1l2.3.4v2.6l-2.3.4a7.9 7.9 0 01-.9 2.1l1.1 2.1-1.8 1.8-2.1-1.1a7.9 7.9 0 01-2.1.9l-.4 2.3h-2.6l-.4-2.3a7.9 7.9 0 01-2.1-.9l-2.1 1.1-1.8-1.8 1.1-2.1a7.9 7.9 0 01-.9-2.1L2 12.3V9.7l2.3-.4c.2-.7.5-1.4.9-2.1L4.1 5.1 5.9 3.3l2.1 1.1c.7-.4 1.4-.7 2.1-.9z" />
                    </svg>
                    <span class="truncate">{{ item.label }}</span>
                </Link>
                <Link
                    v-for="child in item.children || []"
                    :key="child.route"
                    :href="route(child.route)"
                    class="flex h-10 items-center gap-3 rounded-lg py-0 pl-11 pr-3 text-sm transition"
                    :class="isActive(child.match) ? 'bg-accent font-medium text-foreground' : 'font-normal text-muted-foreground hover:bg-accent hover:text-foreground'"
                    @click="sidebarOpen = false"
                >
                    <span class="truncate">{{ child.label }}</span>
                </Link>
                </template>
            </nav>

            <div class="mt-auto rounded-xl border border-border bg-card p-4">
                <div class="text-sm font-semibold text-foreground">{{ userName }}</div>
                <div class="mt-1 truncate text-xs text-muted-foreground">{{ $page.props.auth.user.email }}</div>
            </div>
        </aside>

        <div
            class="min-h-screen transition-[padding] duration-200"
            :class="sidebarCollapsed ? 'lg:pl-0' : 'lg:pl-[280px]'"
        >
            <div class="p-3">
                <div class="app-shell flex min-h-[calc(100vh-1.5rem)] flex-col rounded-2xl border border-border bg-panel">
                    <header class="flex h-16 shrink-0 items-center justify-between gap-3 border-b border-border px-4">
                        <div class="flex min-w-0 items-center gap-1">
                            <button
                                type="button"
                                class="ghost-icon"
                                aria-label="Toggle sidebar"
                                @click="toggleSidebar"
                            >
                                <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                                    <rect width="18" height="18" x="3" y="3" rx="2" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 3v18" />
                                </svg>
                            </button>

                            <Dropdown align="left" width="48" content-classes="rounded-xl border border-border bg-card py-1 shadow-sm">
                                <template #trigger>
                                    <button type="button" class="flex max-w-[220px] items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-accent">
                                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-orange-500 text-xs font-semibold text-on-solid">
                                            {{ userInitial }}
                                        </span>
                                        <span class="truncate font-medium text-foreground">{{ userName }}</span>
                                        <svg class="h-4 w-4 shrink-0 text-muted-foreground" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m7 15 5 5 5-5M7 9l5-5 5 5" />
                                        </svg>
                                    </button>
                                </template>
                                <template #content>
                                    <DropdownLink :href="route('profile.edit')">Profil</DropdownLink>
                                    <DropdownLink :href="route('logout')" method="post" as="button">Çıxış</DropdownLink>
                                </template>
                            </Dropdown>
                        </div>

                        <div class="flex items-center gap-1">
                            <Link
                                :href="route('profile.edit')"
                                class="hidden rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground hover:bg-accent hover:text-foreground sm:inline-flex"
                            >
                                Profil
                            </Link>

                            <Link
                                :href="route('alerts.index')"
                                class="ghost-icon relative"
                                aria-label="Xəbərdarlıqlar"
                            >
                                <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0a3 3 0 11-6 0" />
                                </svg>
                                <span class="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-[var(--notification-dot)] ring-2 ring-background" />
                            </Link>

                            <button
                                type="button"
                                class="ghost-icon"
                                :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                                :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                                @click="toggleTheme"
                            >
                                <svg v-if="!isDark" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                                    <circle cx="12" cy="12" r="4" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
                                </svg>
                                <svg v-else class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9z" />
                                </svg>
                            </button>

                            <Dropdown align="right" width="48" content-classes="rounded-xl border border-border bg-card py-1 shadow-sm">
                                <template #trigger>
                                    <button type="button" class="ml-1 flex h-9 w-9 items-center justify-center rounded-full bg-primary text-sm font-medium text-primary-foreground">
                                        {{ userInitial }}
                                    </button>
                                </template>
                                <template #content>
                                    <DropdownLink :href="route('profile.edit')">Profil</DropdownLink>
                                    <DropdownLink :href="route('logout')" method="post" as="button">Çıxış</DropdownLink>
                                </template>
                            </Dropdown>
                        </div>
                    </header>

                    <div v-if="$slots.header" class="px-5 pt-6 sm:px-6">
                        <slot name="header" />
                    </div>

                    <div v-if="showFlash && (flashSuccess || flashError)" class="px-5 pt-4 sm:px-6">
                        <div :class="flashSuccess ? 'alert-success' : 'alert-error'">
                            {{ flashSuccess || flashError }}
                        </div>
                    </div>

                    <main class="flex-1 px-5 py-6 sm:px-6">
                        <slot />
                    </main>
                </div>
            </div>
        </div>
    </div>
</template>
