<?php

namespace App\Http\Controllers\Api\V1;

use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Http\Requests\ProductRequest;
use App\Models\Product;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The shop's products (Win Plan PP7): a simple list to pick from when opening a contract, with a default price and a
 * cost. No stock is counted. Archived products leave the list and stay on the contracts that sold them. Reading stays
 * open when contract details are switched off; adding and changing ask for them.
 */
final class ProductController
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Product::class);

        $products = Product::query()
            ->when($request->boolean('archived'), fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->orderByRaw('LOWER(name)')->get();

        return response()->json(['data' => $products->map(fn (Product $p) => self::payload($p))->all()]);
    }

    public function store(ProductRequest $request): JsonResponse
    {
        Gate::authorize('create', Product::class);
        Entitlements::for($this->current->get())->assertEnabled(Feature::ContractItems);

        $product = Product::query()->create($request->safe()->only(['name', 'sku', 'default_price', 'cost']));

        return response()->json(['data' => self::payload($product)], 201);
    }

    public function update(ProductRequest $request, Product $product): JsonResponse
    {
        Gate::authorize('update', $product);
        Entitlements::for($this->current->get())->assertEnabled(Feature::ContractItems);

        $product->fill($request->safe()->only(['name', 'sku', 'default_price', 'cost']));
        if ($request->has('archived')) {
            $product->forceFill(['archived_at' => $request->boolean('archived') ? ($product->archived_at ?? now()) : null]);
        }
        $product->save();

        return response()->json(['data' => self::payload($product)]);
    }

    /** @return array<string, mixed> */
    public static function payload(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'default_price' => $product->default_price === null ? null : Money::add($product->default_price, '0', 2),
            'cost' => $product->cost === null ? null : Money::add($product->cost, '0', 2),
            'archived' => $product->archived_at !== null,
        ];
    }
}
