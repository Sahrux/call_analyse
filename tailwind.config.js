import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Poppins', 'ui-sans-serif', 'system-ui', ...defaultTheme.fontFamily.sans],
            },
            fontSize: {
                base: ['14px', '1.5'],
            },
            colors: {
                background: 'var(--card-bg)',
                app: 'var(--app-bg)',
                sidebar: 'var(--sidebar-bg)',
                panel: 'var(--panel-bg)',
                card: 'var(--card-bg)',
                'card-header': 'var(--card-header-bg)',
                'card-inner': 'var(--card-inner-bg)',
                foreground: 'var(--foreground)',
                'muted-foreground': 'var(--muted-foreground)',
                border: 'var(--border)',
                primary: {
                    DEFAULT: 'var(--primary)',
                    foreground: 'var(--primary-foreground)',
                    hover: 'var(--primary-hover)',
                },
                accent: 'var(--accent)',
                'input-bg': 'var(--input-bg)',
                'input-border': 'var(--input-border)',
                'on-solid': 'var(--on-solid)',
                success: 'var(--success)',
                danger: 'var(--danger-foreground)',
                warn: 'var(--warn-foreground)',
                'chart-line': 'var(--chart-line)',
                'chart-strong': 'var(--chart-strong)',
                'chart-point': 'var(--chart-point)',
                'bubble-received': 'var(--bubble-received)',
                'bubble-sent': 'var(--bubble-sent)',
                'bubble-sent-foreground': 'var(--bubble-sent-foreground)',
                'row-hover': 'var(--table-row-hover)',
            },
            boxShadow: {
                card: '0 1px 2px rgba(0, 0, 0, 0.04)',
            },
            borderRadius: {
                card: '16px',
            },
        },
    },

    plugins: [forms],
};
