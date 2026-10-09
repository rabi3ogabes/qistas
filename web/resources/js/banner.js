// Welcome banners: closing one hides it with a short fade and remembers that on this device, by the banner's key. The key
// changes whenever the admin changes the banner, so a new message shows again. Without script the banner simply stays.
export function initWelcomeBanners() {
    document.querySelectorAll('[data-welcome]').forEach((banner) => {
        const close = banner.querySelector('[data-welcome-close]');
        if (!close) return;

        close.addEventListener('click', () => {
            try { localStorage.setItem(`q-welcome-${banner.dataset.welcome}`, 'closed'); } catch { /* private mode: closed for this page only */ }

            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                banner.hidden = true;
                return;
            }

            banner.setAttribute('data-closing', '');
            const done = () => { banner.hidden = true; };
            banner.addEventListener('transitionend', done, { once: true });
            window.setTimeout(done, 400);
        });
    });
}
