import forms from '@tailwindcss/forms';

/**
 * Tailwind config — Phase 1.8d.
 *
 * Палитра/шрифты/радиусы зашиты в CSS-переменные `:root` (design-tokens.css).
 * Тут мы пробрасываем их в Tailwind, чтобы привычные утилиты `bg-surface`,
 * `text-fg-2`, `border-strong`, `font-mono`, `text-base`, `rounded-md`
 * резолвились на семантические токены.
 *
 * Не используем дефолтные tailwind-цвета (`bg-gray-*`, `text-red-500`, и т.д.) —
 * всё через семантику. Если нужен скейл — `text-neutral-700`, `bg-red-50`.
 */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './app/Livewire/**/*.php',
    ],

    theme: {
        extend: {
            colors: {
                // Семантические токены (рекомендуемый API)
                app:        'var(--bg-app)',
                surface:    'var(--bg-surface)',
                'surface-2':'var(--bg-surface-2)',
                sidebar:    'var(--bg-sidebar)',
                hover:      'var(--bg-hover)',
                selected:   'var(--bg-selected)',

                'fg-1': 'var(--fg-1)',
                'fg-2': 'var(--fg-2)',
                'fg-3': 'var(--fg-3)',
                'fg-4': 'var(--fg-4)',
                'fg-on-accent': 'var(--fg-on-accent)',

                border: {
                    DEFAULT: 'var(--border)',
                    strong:  'var(--border-strong)',
                    subtle:  'var(--border-subtle)',
                },

                accent: {
                    DEFAULT: 'var(--accent)',
                    600:     'var(--accent-600)',
                    700:     'var(--accent-700)',
                    bg:      'var(--accent-bg)',
                },

                // Шкальные токены — для тонкой настройки
                neutral: {
                    0:   'var(--neutral-0)',
                    50:  'var(--neutral-50)',
                    100: 'var(--neutral-100)',
                    200: 'var(--neutral-200)',
                    300: 'var(--neutral-300)',
                    400: 'var(--neutral-400)',
                    500: 'var(--neutral-500)',
                    600: 'var(--neutral-600)',
                    700: 'var(--neutral-700)',
                    800: 'var(--neutral-800)',
                    900: 'var(--neutral-900)',
                },
                red: {
                    50:  'var(--red-50)',
                    100: 'var(--red-100)',
                    300: 'var(--red-300)',
                    500: 'var(--red-500)',
                    600: 'var(--red-600)',
                    700: 'var(--red-700)',
                    800: 'var(--red-800)',
                },
                amber: {
                    50:  'var(--amber-50)',
                    600: 'var(--amber-600)',
                    700: 'var(--amber-700)',
                },
                emerald: {
                    50:  'var(--emerald-50)',
                    600: 'var(--emerald-600)',
                    700: 'var(--emerald-700)',
                },
                sky: {
                    50:  'var(--sky-50)',
                    500: 'var(--sky-500)',
                    600: 'var(--sky-600)',
                    700: 'var(--sky-700)',
                },
                violet: {
                    50:  'var(--violet-50)',
                    600: 'var(--violet-600)',
                    700: 'var(--violet-700)',
                },
            },

            fontFamily: {
                sans: 'var(--font-sans)',
                mono: 'var(--font-mono)',
            },

            fontSize: {
                xs:    ['var(--fs-xs)',   { lineHeight: 'var(--lh-snug)' }],
                sm:    ['var(--fs-sm)',   { lineHeight: 'var(--lh-snug)' }],
                base:  ['var(--fs-base)', { lineHeight: 'var(--lh-normal)' }],
                md:    ['var(--fs-md)',   { lineHeight: 'var(--lh-normal)' }],
                lg:    ['var(--fs-lg)',   { lineHeight: 'var(--lh-snug)' }],
                xl:    ['var(--fs-xl)',   { lineHeight: 'var(--lh-tight)' }],
                '2xl': ['var(--fs-2xl)',  { lineHeight: 'var(--lh-tight)' }],
                '3xl': ['var(--fs-3xl)',  { lineHeight: '1.1' }],
                '4xl': ['var(--fs-4xl)',  { lineHeight: '1.05' }],
            },

            borderRadius: {
                sm:   'var(--r-sm)',
                DEFAULT: 'var(--r-md)',
                md:   'var(--r-md)',
                lg:   'var(--r-lg)',
                xl:   'var(--r-xl)',
                full: 'var(--r-pill)',
            },

            boxShadow: {
                sm: 'var(--shadow-sm)',
                DEFAULT: 'var(--shadow-md)',
                md: 'var(--shadow-md)',
                lg: 'var(--shadow-lg)',
            },

            ringColor: {
                DEFAULT: 'var(--ring)',
            },

            transitionTimingFunction: {
                DEFAULT: 'cubic-bezier(0.2, 0, 0, 1)',
            },

            spacing: {
                'topbar': 'var(--topbar-h)',
                'rail':   'var(--rail-w)',
                'list':   'var(--list-w)',
                'inspector': 'var(--inspector-w)',
                'row':    'var(--row-h)',
                'row-compact': 'var(--row-h-compact)',
            },
        },
    },

    plugins: [forms],
};
