<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CollectionShowRequest;
use App\Http\Resources\CollectionResource;
use App\Models\Collection;

class CollectionsController extends Controller
{
    /**
     * Show collection by slug with optional category filter
     */
    public function show(CollectionShowRequest $request, string $slug)
    {
        $collection = Collection::active()
            ->where('slug', $slug)
            ->firstOrFail();

        $products = $collection->products()
            ->when($request->category, fn($q) => 
                $q->whereHas('categories', fn($c) => 
                    $c->where('slug', $request->category)
                )
            )
            ->paginate($request->limit ?? 12);

        return CollectionResource::make($collection)->additional([
            'products' => $products,
        ]);
    }

    /**
     * Optional: List all active collections
     */
    public function index()
    {
        $collections = Collection::active()->get();
        return CollectionResource::collection($collections);
    }
}
