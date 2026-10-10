{{-- Settings → Backups & data (Win Plan PP10): when the books were last copied, the copies kept, and everything in one file. --}}
@php
    $kindLabel = ['nightly' => __('Nightly copy'), 'manual' => __('Download')];
    $size = fn (int $bytes): string => $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
@endphp
<x-layouts.app :title="__('Backups & data')" section="backups">
    <x-page-head :title="__('Backups & data')">
        <x-slot:subtitle>{{ __('Your books are copied every night. You can take everything with you at any time, on any plan.') }}</x-slot:subtitle>
    </x-page-head>

    <div class="backup-grid">
        <section class="card card-pad backup-status" aria-labelledby="last-title">
            <span class="backup-mark" data-ok="{{ $lastBackup ? 'true' : 'false' }}" aria-hidden="true"><x-icon :name="$lastBackup ? 'check' : 'clock'" :size="22" /></span>
            <div>
                <h2 id="last-title" class="card-title">{{ __('Last copy') }}</h2>
                @if ($lastBackup)
                    <p class="backup-when">{{ $tenant->localTime($lastBackup)->translatedFormat('j M Y, H:i') }}</p>
                    <p class="field-hint">{{ __('A new copy is made every night. The last :count are kept, encrypted.', ['count' => $kept]) }}</p>
                @else
                    <p class="backup-when">{{ __('Tonight') }}</p>
                    <p class="field-hint">{{ __('The first copy is made tonight. After that, one every night, and the last :count are kept, encrypted.', ['count' => $kept]) }}</p>
                @endif
            </div>
        </section>

        <section class="card card-pad stack" aria-labelledby="everything-title">
            <h2 id="everything-title" class="card-title">{{ __('Download everything') }}</h2>
            <p class="field-hint">{{ __('Customers, contracts, instalments, payments, what was sold and investors, each on its own sheet. Arabic opens correctly in Excel.') }}</p>
            <div class="backup-actions">
                <form method="POST" action="{{ route('app.exports.store') }}">
                    @csrf
                    <input type="hidden" name="format" value="xlsx">
                    <button class="btn btn-gold" type="submit"><x-icon name="download" :size="18" /> {{ __('Excel file') }}</button>
                </form>
                <form method="POST" action="{{ route('app.exports.store') }}">
                    @csrf
                    <input type="hidden" name="format" value="csv">
                    <button class="btn btn-quiet" type="submit"><x-icon name="sheet" :size="18" /> {{ __('CSV files (zip)') }}</button>
                </form>
            </div>
        </section>
    </div>

    <section class="card" aria-labelledby="copies-title">
        <h2 id="copies-title" class="card-title card-pad" style="padding-bottom:0">{{ __('Copies kept') }}</h2>
        @if ($copies->isEmpty())
            <div class="empty">
                <span class="empty-icon"><x-icon name="cloud" :size="24" /></span>
                <h3>{{ __('No copies yet') }}</h3>
                <p>{{ __('Tonight’s copy will appear here.') }}</p>
            </div>
        @else
            <div class="table-wrap">
                <table class="table table-stack">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Made') }}</th>
                            <th scope="col">{{ __('Kind') }}</th>
                            <th scope="col" class="num">{{ __('Customers') }}</th>
                            <th scope="col" class="num">{{ __('Contracts') }}</th>
                            <th scope="col" class="num">{{ __('Payments') }}</th>
                            <th scope="col" class="num">{{ __('Size') }}</th>
                            <th scope="col"><span class="sr-only">{{ __('Download') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($copies as $copy)
                            <tr>
                                <td data-label="{{ __('Made') }}" class="money">{{ $tenant->localTime($copy->created_at)->translatedFormat('j M Y, H:i') }}</td>
                                <td data-label="{{ __('Kind') }}">{{ $kindLabel[$copy->kind] ?? $copy->kind }} · {{ strtoupper($copy->format) }}</td>
                                <td data-label="{{ __('Customers') }}" class="num money">{{ $copy->row_counts['customers'] ?? 0 }}</td>
                                <td data-label="{{ __('Contracts') }}" class="num money">{{ $copy->row_counts['contracts'] ?? 0 }}</td>
                                <td data-label="{{ __('Payments') }}" class="num money">{{ $copy->row_counts['payments'] ?? 0 }}</td>
                                <td data-label="{{ __('Size') }}" class="num money">{{ $size($copy->size) }}</td>
                                <td data-label="{{ __('Download') }}" class="num"><a class="btn btn-quiet btn-sm" href="{{ route('app.exports.download', $copy) }}"><x-icon name="download" :size="16" /> {{ __('Download') }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-layouts.app>
