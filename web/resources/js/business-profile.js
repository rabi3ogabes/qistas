// The business profile page: a signature drawn with a finger, a pen or the mouse, sent as a transparent PNG, and a logo
// that uploads as soon as it is chosen. Plain DOM code, no Alpine expressions (the site runs Alpine's CSP build).

const INK = '#0B1F44';

function initPad(form) {
    const canvas = form.querySelector('canvas');
    const input = form.querySelector('input[name="drawing"]');
    const save = form.querySelector('[data-signature-save]');
    const clear = form.querySelector('[data-signature-clear]');
    const context = canvas.getContext('2d');
    let drawing = false;
    let drawn = false;

    // Draw at the screen's own resolution so the saved signature is sharp on paper.
    const fit = () => {
        const ratio = window.devicePixelRatio || 1;
        const box = canvas.getBoundingClientRect();
        canvas.width = Math.round(box.width * ratio);
        canvas.height = Math.round(box.height * ratio);
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
        context.lineWidth = 2.4;
        context.lineCap = 'round';
        context.lineJoin = 'round';
        context.strokeStyle = INK;
        drawn = false;
        save.disabled = true;
    };
    const point = (event) => {
        const box = canvas.getBoundingClientRect();
        return [event.clientX - box.left, event.clientY - box.top];
    };

    fit();
    canvas.addEventListener('pointerdown', (event) => {
        drawing = true;
        canvas.setPointerCapture(event.pointerId);
        context.beginPath();
        context.moveTo(...point(event));
    });
    canvas.addEventListener('pointermove', (event) => {
        if (!drawing) return;
        context.lineTo(...point(event));
        context.stroke();
        drawn = true;
        save.disabled = false;
    });
    const stop = () => { drawing = false; };
    canvas.addEventListener('pointerup', stop);
    canvas.addEventListener('pointercancel', stop);
    clear.addEventListener('click', fit);
    form.addEventListener('submit', (event) => {
        if (!drawn) {
            event.preventDefault();
            return;
        }
        input.value = canvas.toDataURL('image/png');
    });
}

export function initBusinessProfile() {
    document.querySelectorAll('[data-signature-pad]').forEach(initPad);
    document.querySelectorAll('input[data-autosubmit]').forEach((input) => {
        input.addEventListener('change', () => { if (input.files.length > 0) input.form.requestSubmit(); });
    });
}
