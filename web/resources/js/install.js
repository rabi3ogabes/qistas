// The web app is installable (Win Plan PL04). On its pages only (the body of the web app's layout), a service worker is
// registered for /app; it keeps the app's own files and an offline page, never a page or an answer with data in it.
export function registerServiceWorker() {
    if (!('serviceWorker' in navigator) || !document.body || !document.body.classList.contains('app-body')) return;

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/app' }).catch(() => {});
    });
}
