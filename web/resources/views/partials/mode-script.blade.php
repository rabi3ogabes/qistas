{{-- Applies a saved light/dark choice before first paint, so there is no flash of the wrong theme. --}}
<script @if ($nonce = \Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ $nonce }}" @endif>
    try {
        var m = localStorage.getItem('q-mode');
        if (m === 'light' || m === 'dark') document.documentElement.setAttribute('data-q-mode', m);
    } catch (e) {}
</script>
