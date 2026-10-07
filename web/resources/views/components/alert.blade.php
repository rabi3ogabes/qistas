@props(['type' => 'status', 'message' => null])
@if ($message)
    <p class="alert alert-{{ $type }}" role="{{ $type === 'status' ? 'status' : 'alert' }}">{{ $message }}</p>
@endif
