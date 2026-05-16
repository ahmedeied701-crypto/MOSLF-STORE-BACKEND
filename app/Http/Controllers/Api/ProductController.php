<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

// use App\Actions\Product\Create\CreateProductAction;
// use App\Actions\Product\Delete\DeleteProductAction;
// use App\Actions\Product\Update\UpdateProductAction;
// use App\Http\Requests\Product\StoreProductRequest;
// use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\Product\ProductResource;
use App\Models\Product;
use App\Repositories\Public\Contracts\ProductPublicRepositoryInterface;
// use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\DTOs\Product\PublicProductFilterData;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * ProductController
 *
 * Intentionally "slim" — this class is a pure HTTP adapter.
 * It ONLY: validates input (via FormRequests), calls Actions, and returns responses.
 * Zero business logic lives here. Zero database queries. Zero model manipulation.
 */
class ProductController
{
    public function __construct(
        private readonly ProductPublicRepositoryInterface $repository,
    ) {}

    /**
     * GET /api/products
     * Supports: ?status=active&search=widget&price_min=10&sort_by=price&sort_dir=asc&per_page=20
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = PublicProductFilterData::fromRequest($request);

        $products = $this->repository->paginate($filters);

        return ProductResource::collection($products)
            ->additional(['context' => 'public']);
    }

    /**
     * GET /api/products/{product}
     */
    public function show(Product $product): ProductResource
    {
        $product->load(['variations']);

        return new ProductResource($product);
    }
}
