{{-- Who did what on a ledger line (Win Plan PP16): who recorded it and when, and for a voided one who voided it and why. --}}
<span class="cell-sub who">
    @if ($line->createdBy)
        {{ __('Recorded by :name, :when', ['name' => $line->createdBy->name, 'when' => $line->created_at->translatedFormat('j M, H:i')]) }}
    @else
        {{ __('Recorded :when', ['when' => $line->created_at->translatedFormat('j M, H:i')]) }}
    @endif
</span>
@if ($line->getAttribute('reversal'))
    @php($reversal = $line->getAttribute('reversal'))
    <span class="cell-sub who who-void">
        {{ $reversal->createdBy
            ? __('Voided by :name, :when', ['name' => $reversal->createdBy->name, 'when' => $reversal->created_at->translatedFormat('j M, H:i')])
            : __('Voided :when', ['when' => $reversal->created_at->translatedFormat('j M, H:i')]) }}@if ($reversal->note): {{ $reversal->note }}@endif
    </span>
@endif
