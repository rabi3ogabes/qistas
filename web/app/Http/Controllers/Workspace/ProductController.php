<?php

namespace App\Http\Controllers\Workspace;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Http\Requests\ProductRequest;
use App\Models\Product;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** The products page of the web app (Win Plan PP7): the list to pick from when opening a contract. */
final class ProductController
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Product::class);
        $archived = $request->boolean('archived');

        return view('app.products.index', [
            'products' => Product::query()->when($archived, fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))->orderByRaw('LOWER(name)')->get(),
            'archived' => $archived,
            'canManage' => Gate::allows('create', Product::class) && Entitlements::for($this->current->get())->check(Feature::ContractItems)->enabled(),
            'currency' => $this->current->get()?->currency,
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        Gate::authorize('create', Product::class);
        Entitlements::for($this->current->get())->assertEnabled(Feature::ContractItems);

        $product = Product::query()->create($request->safe()->only(['name', 'sku', 'default_price', 'cost']));

        return redirect()->route('app.products.index')->with('status', __(':name was added.', ['name' => $product->name]));
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);
        Entitlements::for($this->current->get())->assertEnabled(Feature::ContractItems);

        $product->fill($request->safe()->only(['name', 'sku', 'default_price', 'cost']));
        if ($request->has('archived')) {
            $product->forceFill(['archived_at' => $request->boolean('archived') ? ($product->archived_at ?? now()) : null]);
        }
        $product->save();

        return redirect()->route('app.products.index', $product->archived_at === null ? [] : ['archived' => 1])->with('status', __('Saved.'));
    }
}
