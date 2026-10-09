// The language button. Every choice is a plain link, so nothing here is needed for it to work; this adds what a good
// control does: Escape closes it and returns to the button, the arrow keys move through the languages, opening it puts
// focus on the current one, and a click anywhere else closes it (and only one is ever open).

export function initLanguageSwitchers() {
    const switchers = () => [...document.querySelectorAll('[data-lang-switcher]')];
    if (switchers().length === 0) return;

    switchers().forEach((one) => {
        one.addEventListener('toggle', () => {
            if (!one.open) return;
            switchers().forEach((other) => { if (other !== one) other.open = false; });
            (one.querySelector('[aria-current="true"]') ?? one.querySelector('.lang-option'))?.focus();
        });
    });

    document.addEventListener('click', (event) => {
        switchers().forEach((one) => { if (one.open && !one.contains(event.target)) one.open = false; });
    });

    document.addEventListener('keydown', (event) => {
        const open = switchers().find((one) => one.open);
        if (!open) return;

        if (event.key === 'Escape') {
            open.open = false;
            open.querySelector('summary')?.focus();

            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const options = [...open.querySelectorAll('.lang-option')];
            const at = options.indexOf(document.activeElement);
            const step = event.key === 'ArrowDown' ? 1 : -1;
            options[(at + step + options.length) % options.length]?.focus();
        }
    });
}
