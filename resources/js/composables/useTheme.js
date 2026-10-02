import { computed, ref } from 'vue';

const STORAGE_KEY = 'theme';

const readAppliedTheme = () => {
    if (typeof document === 'undefined') {
        return 'light';
    }

    return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
};

const theme = ref(readAppliedTheme());

const applyTheme = (value) => {
    const next = value === 'dark' ? 'dark' : 'light';
    theme.value = next;
    document.documentElement.classList.toggle('dark', next === 'dark');
    document.documentElement.style.colorScheme = next;
    localStorage.setItem(STORAGE_KEY, next);
};

export function useTheme() {
    const isDark = computed(() => theme.value === 'dark');

    const setTheme = (value) => {
        applyTheme(value);
    };

    const toggleTheme = () => {
        setTheme(theme.value === 'dark' ? 'light' : 'dark');
    };

    return {
        theme,
        isDark,
        toggleTheme,
        setTheme,
    };
}
