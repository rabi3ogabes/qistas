{{-- What a document was narrowed to (an investor, a product), under its title, so it is never read as the whole business's. --}}
@foreach ($narrowedTo ?? [] as $narrowing)
    <p class="narrowed">{{ $narrowing }}</p>
@endforeach
