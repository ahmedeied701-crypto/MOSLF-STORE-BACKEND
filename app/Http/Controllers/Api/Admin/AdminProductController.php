<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Actions\Product\Create\CreateProductAction;
use App\Actions\Product\Update\UpdateProductAction;
use App\Actions\Product\Update\UpdateVariationAction;
use App\Actions\Product\Delete\DeleteProductAction;
use App\Repositories\Contracts\ProductRepositoryInterface;
use App\Http\Resources\Product\ProductResource;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Product;
use App\Models\ProductVariation;
use App\DTOs\Product\ProductFilterData;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminProductController extends Controller
{
    public function __construct(
        private readonly ProductRepositoryInterface $repository,
        private readonly CreateProductAction $createAction,
        private readonly UpdateProductAction $updateAction,
        private readonly DeleteProductAction $deleteAction,
    ) {}

    /**
     * GET /api/v1/admin/products
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = ProductFilterData::fromRequest($request);

        $products = $this->repository->paginate($filters);

        return ProductResource::collection($products)
            ->additional(['context' => 'admin']);
    }

    /**
     * POST /api/v1/admin/products
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->createAction->execute($request->validated());



        return (new ProductResource(
            $product->loadAdminRelations()
        ))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/v1/admin/products/{product}
     */
    public function show(Product $product): ProductResource
    {
        return new ProductResource(
            $product->loadAdminRelations()
        );
    }

    /**
     * PATCH /api/v1/admin/products/{product}
     */
    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {

        $product = $this->updateAction->execute($product, $request->validated());

        return new ProductResource(
            $product->loadAdminRelations()
        );
    }

    /**
     * DELETE /api/v1/admin/products/{product}
     */
    public function destroy(Product $product): JsonResponse
    {
        $this->deleteAction->execute($product);

        return response()->json([
            'message' => 'Product archived successfully.'
        ]);
    }

    /**
     * Update product variation (SKU, price, attributes)
     * PUT /api/v1/admin/products/{product}/variations/{variation}
     */
    public function updateVariation(
        Request $request,
        Product $product,
        int $variationId,
        UpdateVariationAction $action
    ) {
        $variation = $product->variations()->findOrFail($variationId);

        $updated = $action->execute($variation, $request->only([
            'status',
            'sku',
            'price',
            'attributes'
        ]));

        return response()->json([
            'data' => $updated->fresh()
        ]);
    }
}
