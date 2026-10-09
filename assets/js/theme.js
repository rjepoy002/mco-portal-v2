(() => {
    'use strict';

    const THEME_KEY = 'mco-theme';

    function isDarkMode() {
        return document.documentElement.classList.contains('dark');
    }

    function updateThemeToggle() {
        const toggle = document.getElementById('themeToggle');

        if (!toggle) return;

        const dark = isDarkMode();
        const label = dark ? 'Switch to light mode' : 'Switch to dark mode';
        const icon = dark
            ? '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5 shrink-0"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"></path></svg>'
            : '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5 shrink-0"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z"></path></svg>';

        toggle.setAttribute('aria-label', label);
        toggle.setAttribute('title', label);
        toggle.innerHTML = `${icon}<span class="portal-expanded-only">Appearance</span><span class="portal-expanded-only ml-auto text-xs font-medium text-slate-500 dark:text-slate-400">${dark ? 'Light mode' : 'Dark mode'}</span>`;
    }
    function applyTheme(theme) {
        const dark = theme === 'dark';
        document.documentElement.classList.toggle('dark', dark);
        localStorage.setItem(THEME_KEY, theme);
        updateThemeToggle();
    }

    function toggleTheme() {
        applyTheme(isDarkMode() ? 'light' : 'dark');
    }

    document.addEventListener('DOMContentLoaded', () => {
        updateThemeToggle();
        document.getElementById('themeToggle')?.addEventListener('click', toggleTheme);
    });

    window.mcoTheme = { applyTheme, isDarkMode, toggleTheme, updateThemeToggle };
})();
