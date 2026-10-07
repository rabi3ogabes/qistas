// Light / dark. The page follows the system until the person chooses; the choice is remembered on this device.
// A tiny inline script in the layout applies a saved choice before first paint, so there is no flash.
const KEY = 'q-mode';

function current() {
    const explicit = document.documentElement.getAttribute('data-q-mode');
    if (explicit === 'light' || explicit === 'dark') return explicit;

    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

export function initTheme() {
    document.querySelectorAll('[data-q-mode-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const next = current() === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-q-mode', next);
            try { localStorage.setItem(KEY, next); } catch { /* private mode: the choice just lasts for this page */ }
            button.setAttribute('aria-pressed', String(next === 'dark'));
        });

        button.setAttribute('aria-pressed', String(current() === 'dark'));
    });
}
