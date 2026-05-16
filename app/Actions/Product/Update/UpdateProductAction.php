<?php

declare(strict_types=1);

namespace App\Actions\Product\Update;

use App\Models\Product;
use App\Actions\Product\Update\UpdateVariationAction;
use App\Actions\Product\Sync\SyncProductImagesAction;
use Illuminate\Support\Facades\DB;

/**
 * PRODUCT UPDATE CONTRACT
 *
 * This action performs an UPSERT operation on product variations:
 *
 * - If variation has ID → update existing record
 * - If variation has NO ID → create new variation under product
 * - Inventory is NOT updated here (handled separately via InventoryController)
 *
 * This is intentional behavior (not a bug).
 */
/**
 * UpdateProductAction
 *
 * Only updates product metadata/pricing fields.
 * Inventory changes must go through UpdateStockAction — never touch
 * quantity_on_hand directly here. This enforces the audit trail.
 */
final class UpdateProductAction
{

    public function __construct(
        private UpdateVariationAction $updateVariation,
        private SyncProductImagesAction $syncImages,
    ) {}
    /**
     * @param  array{
     *     name?: string,
     *     description?: ?string,
     *     status?: string,
     *     metadata?: ?array,
     * } $data
     */
    public function execute(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data): Product {

            // Restore Product if is not activated
            if ($product->trashed() && isset($data['status']) && $data['status'] !== 'archived') {
                $product->restore();
            }

            // 1. Update product
            $product->update(array_filter([
                'name'        => $data['name'] ?? null,
                'description' => array_key_exists('description', $data) ? $data['description'] : null,
                'status'      => $data['status'] ?? null,
                'metadata'    => array_key_exists('metadata', $data) ? $data['metadata'] : null,
            ], fn($val) => $val !== null));


            // 3. Update/Create variations
            foreach ($data['variations'] ?? [] as $variationData) {

                if (isset($variationData['id'])) {
                    $variation = $product->variations()
                        ->findOrFail($variationData['id']);

                    $this->updateVariation->execute($variation, $variationData);
                } else {
                    $variation = $product->variations()->create([
                        'status' => $variationData['status'] ?? 'active',
                        'sku' => $variationData['sku'],
                        'price' => $variationData['price'],
                        'attributes' => $variationData['attributes'] ?? [],
                    ]);
                }
                // 4. Sync images
                if (isset($variationData['images'])) {
                    $this->syncImages->execute($variation, $variationData['images']);
                }
            }
            return $product->loadAdminRelations();



            // return $product->load('variations');
        });
    }
}
