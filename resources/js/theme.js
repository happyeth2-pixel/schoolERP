const STORAGE_KEY = 'theme';

export function getStoredTheme() {
    try {
        return localStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}

export function applyTheme(theme) {
    const isDark = theme === 'dark';
    document.documentElement.classList.toggle('dark', isDark);
}

export function setTheme(theme) {
    try {
        localStorage.setItem(STORAGE_KEY, theme);
    } catch {
        // ignore -- private browsing / storage blocked, theme just won't persist
    }
    applyTheme(theme);
}

/**
 * Called once, as early as possible, before React mounts -- avoids a
 * flash of the wrong theme on load. Defaults to light (not the OS
 * preference) since the whole point of this toggle is manual control.
 */
export function initTheme() {
    applyTheme(getStoredTheme() === 'dark' ? 'dark' : 'light');
}
