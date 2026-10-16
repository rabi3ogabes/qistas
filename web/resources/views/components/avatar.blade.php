{{-- A person's face: their profile photo when they have one, else the first letter of their name. --}}
@props(['user', 'size' => 'md'])
@php
    // Only the photo's fingerprint is read here (not the picture): it makes the address change when the photo does.
    $version = app(\App\Support\RequestMemo::class)->remember('photos', (string) $user->id, fn () => \App\Models\UserPhoto::query()->whereKey($user->id)->value('sha256'));
    $own = auth()->id() === $user->id;
@endphp
<span {{ $attributes->class(['avatar', 'avatar-'.$size, 'avatar-photo' => $version !== null && $own]) }} aria-hidden="true">
    @if ($version !== null && $own)
        <img src="{{ route('app.profile.photo', ['v' => substr($version, 0, 12)]) }}" alt="" width="96" height="96" decoding="async">
    @else
        {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
    @endif
</span>
