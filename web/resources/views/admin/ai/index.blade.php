{{-- Reading notebook photos and PDFs (Win Plan K9): the Anthropic key, never shown back, and the model. --}}
<x-layouts.admin :title="__('AI reading')" section="ai">
    <x-page-head :title="__('AI reading')">
        <x-slot:subtitle>{{ __('Shops can photograph a notebook page or send another app’s PDF report; Claude reads it into rows that go to the same trial run as an Excel file.') }}</x-slot:subtitle>
    </x-page-head>

    <div class="detail-layout">
        <section class="card card-pad stack" aria-labelledby="ai-key-title">
            <h2 id="ai-key-title">{{ __('Anthropic key') }}</h2>
            <p>
                @if ($configured)
                    <span class="badge badge-ok">{{ __('A key is saved') }}</span>
                @else
                    <span class="badge badge-warn">{{ __('No key yet: photos and PDFs are not offered') }}</span>
                @endif
            </p>
            <form method="POST" action="{{ route('admin.ai.update') }}" class="form">
                @csrf
                @method('PUT')
                <x-field name="key" type="password" :label="$configured ? __('Replace the key') : __('Key')" :hint="__('Kept encrypted and never shown again. It starts with sk-ant-.')" autocomplete="off" spellcheck="false" />
                <x-field name="model" :label="__('Model')" :value="$model" autocomplete="off" spellcheck="false" />
                <div class="form-actions"><button class="btn btn-gold" type="submit">{{ __('Save') }}</button></div>
            </form>
            @if ($configured)
                <form method="POST" action="{{ route('admin.ai.update') }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="remove" value="1">
                    <button class="btn btn-ghost btn-sm danger-text" type="submit">{{ __('Remove the key') }}</button>
                </form>
            @endif
        </section>

        <section class="card card-pad stack" aria-labelledby="ai-use-title">
            <h2 id="ai-use-title">{{ __('This month') }}</h2>
            <p class="figure-value money">{{ $pages }}</p>
            <p class="muted">{{ __('Pages read across every workspace. Pro includes 50 a month for each workspace.') }}</p>
            <p class="field-hint">{{ __('Each photo is drawn again without its hidden data before it is sent, and nothing a shop sends is saved until it confirms its import.') }}</p>
        </section>
    </div>
</x-layouts.admin>
