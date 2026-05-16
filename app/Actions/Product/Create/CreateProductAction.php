<?php

declare(strict_types=1);

namespace App\Actions\Product\Create;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * CreateProductAction
 *
 * Single responsibility: Create a product and atomically provision its inventory ledger.
 *supports multi-image uploads for both design 'canvas' and 'gallery'.
 * Why an Action class?
 *  - Keeps the controller a thin HTTP adapter.
 *  - Can be called from CLI commands, queue jobs, or other actions.
 *  - Trivially unit-testable without HTTP overhead.
 */
final class CreateProductAction
{
    /**
     * @param  array{
     *     name: string,
     *     description: ?string,
     *     sku: ?string,
     *     price: float|string,
     *     cost_price: ?float,
     *     currency: ?string,
     *     status: ?string,
     *     metadata: ?array,
     *     initial_quantity: ?int,
     *     reorder_point: ?int,
     *     reorder_quantity: ?int,
     *     location: ?string,
     * } $data
     */

    public function execute(array $data): Product
    {
        return DB::transaction(function () use ($data): Product {

            // 1. Create product
            $product = Product::create([
                'name'        => $data['name'],
                'description' => $data['description'] ?? null,
                'status'      => $data['status'] ?? 'active',
                'metadata'    => $data['metadata'] ?? null,
            ]);

            // Guard clause (important)
            if (empty($data['variations'])) {
                throw new \InvalidArgumentException('Product must have at least one variation');
            }

            foreach ($data['variations'] as $variationData) {

                // 2. Create variation
                $variation = $product->variations()->create([
                    'status'     => $variationData['status'] ?? 'active',
                    'sku'        => $variationData['sku'] ?? $this->generateSku($data['name']),
                    'price'      => $variationData['price'],
                    'attributes' => $variationData['attributes'] ?? [],
                ]);

                // 3. Images (safe handling)
                if (!empty($variationData['images']) && is_array($variationData['images'])) {
                    foreach ($variationData['images'] ?? [] as $imageData) {
                        $file = $imageData['file'];

                        $path = $file->store("products/{$product->id}/variations", 'public');

                        $variation->images()->create([
                            'image_path' => $path,
                            'type' => $imageData['type'],
                            'side' => $imageData['side'] ?? null,
                            'is_default' => $imageData['is_default'] ?? false,
                            'sort_order' => $imageData['sort_order'] ?? 0,
                        ]);
                    }
                }

                // 4. Inventory 
                $variation->inventory()->create([
                    'quantity_on_hand' => $variationData['stock']
                        ?? $data['initial_quantity']
                        ?? 0,
                ]);
            }

            // 5. Return hydrated object
            return $product->loadAdminRelations();
        });
    }

    /**
     * Generate a unique SKU for the variation.
     */
    private function generateSku(string $name): string
    {
        $base = \Illuminate\Support\Str::slug($name);

        do {
            $sku = strtoupper($base . '-' . random_int(1000, 9999));
        } while (\App\Models\ProductVariation::where('sku', $sku)->exists());

        return $sku;
    }
}
