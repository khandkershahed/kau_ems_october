import { defineStore } from 'pinia';

export const useThemeStore = defineStore('theme', {
    state: () => ({
        isDark: false,
    }),
    actions: {
        initTheme() {
            // Check local storage or system preference
            if (
                localStorage.theme === 'dark' ||
                (!('theme' in localStorage) &&
                    window.matchMedia('(prefers-color-scheme: dark)').matches)
            ) {
                this.isDark = true;
                document.documentElement.classList.add('dark');
            } else {
                this.isDark = false;
                document.documentElement.classList.remove('dark');
            }
        },
        toggleTheme() {
            this.isDark = !this.isDark;
            if (this.isDark) {
                document.documentElement.classList.add('dark');
                localStorage.theme = 'dark';
            } else {
                document.documentElement.classList.remove('dark');
                localStorage.theme = 'light';
            }
        },
    },
});
