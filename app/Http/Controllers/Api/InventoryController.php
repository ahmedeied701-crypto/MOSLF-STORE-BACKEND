<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Inventory\UpdateStockAction;
use App\Exceptions\InsufficientStockException;
use App\Http\Requests\Inventory\StockMovementRequest;
use App\Http\Resources\Inventory\InventoryResource;
use App\Http\Resources\Inventory\StockMovementResource;
use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * InventoryController
 *
 * Handles stock-level reads and all stock movement writes.
 * Movement creation is the ONLY way to modify inventory quantities.
 */
class InventoryController
{
    public function __construct(
        private readonly UpdateStockAction $updateStockAction,
    ) {}

    /**
     * GET /api/products/{product}/variations/{variation}/inventory
     */
    public function show(Product $product, ProductVariation $variation): InventoryResource
    {
        $product->load('variations.inventory');

        $inventory = $variation->inventory;

        abort_if(is_null($inventory), 404, 'Inventory ledger not found for this product.');

        return new InventoryResource($inventory);
    }

    /**
     * GET /api/products/{product}/variations/{variation}/inventory
     */
    public function movements(Product $product, ProductVariation $variation): AnonymousResourceCollection
    {
        $product->load('variations.inventory');

        $inventory = $variation->inventory;

        abort_if(is_null($inventory), 404, 'Inventory ledger not found.');

        $movements = $inventory
            ->stockMovements()
            ->with('creator')
            ->latest()
            ->paginate(20);

        return StockMovementResource::collection($movements);
    }

    /**
     * POST /api/products/{product}/variations/{variation}/inventory
     *
     * The single authorized entry point for all stock changes.
     */
    public function addMovement(
        StockMovementRequest $request,
        Product $product,
        ProductVariation $variation
    ): JsonResponse {
        $product->load('variations.inventory');

        $inventory = $variation->inventory;

        abort_if(is_null($inventory), 404, 'Inventory ledger not found.');

        try {
            $movement = $this->updateStockAction->execute(
                inventory: $inventory,
                data: [
                    ...$request->validated(),
                    'type' => \App\Enums\StockMovementType::from($request->validated('type')),
                ],
            );
        } catch (InsufficientStockException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => [
                    'quantity' => [
                        "Available stock: {$e->available}. Requested: {$e->requested}.",
                    ],
                ],
            ], 422);
        }

        return (new StockMovementResource($movement))
            ->response()
            ->setStatusCode(201);
    }
}
