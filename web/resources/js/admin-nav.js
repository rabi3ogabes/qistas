// The admin menu panel. The page works with no script at all: the menu button is a link to #admin-nav and the panel opens
// with :target. This adds what a good panel does: it opens without jumping the page, focus moves in and comes back, Escape
// and the dark area close it, the page behind cannot be tabbed into or scrolled, and a wide screen closes it for good.
export function initAdminNav() {
    const rail = document.getElementById('admin-nav');
    if (!rail) return;

    const openers = [...document.querySelectorAll('a[href="#admin-nav"]')];
    const behind = [...document.querySelectorAll('.admin-bar, .admin-main')];
    const wide = window.matchMedia('(min-width: 64rem)');
    let opener = null;

    const setOpen = (open, from = null) => {
        if (open) opener = from;
        rail.toggleAttribute('data-open', open);
        document.documentElement.classList.toggle('admin-nav-open', open);
        behind.forEach((el) => el.toggleAttribute('inert', open));
        openers.forEach((el) => el.setAttribute('aria-expanded', String(open)));

        if (open) {
            (rail.querySelector('.rail-nav a[aria-current="page"]') ?? rail.querySelector('.rail-nav a'))?.focus({ preventScroll: true });
        } else {
            opener?.focus({ preventScroll: true });
            opener = null;
        }
    };

    openers.forEach((el) => el.addEventListener('click', (event) => {
        event.preventDefault();
        setOpen(true, el);
    }));

    rail.querySelectorAll('.admin-scrim, .rail-close').forEach((el) => el.addEventListener('click', (event) => {
        event.preventDefault();
        setOpen(false);
    }));

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && rail.hasAttribute('data-open')) setOpen(false);
    });

    wide.addEventListener('change', () => {
        if (wide.matches && rail.hasAttribute('data-open')) setOpen(false);
    });
}
