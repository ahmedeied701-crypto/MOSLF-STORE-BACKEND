<?php

declare(strict_types=1);

namespace App\Actions\Product\Update;

use App\Models\ProductVariation;

/**
 * UpdateVariationAction
 *
 * Responsible for updating an existing Product Variation.
 *
 * RULES:
 * - Does NOT create variations
 * - Does NOT modify inventory
 * - Only updates provided fields (partial update)
 * - Null values are ignored (prevent accidental overwrite)
 * - attributes field is fully replaced if provided
 *
 * This is a pure domain update operation used by:
 * - UpdateProductAction
 * - Admin Variation API endpoints
 */
final class UpdateVariationAction
{
    public function execute(ProductVariation $variation, array $data): ProductVariation
    {
        $variation->update(array_filter([
            'status'     => $data['status'] ?? 'active',
            'sku'        => $data['sku'] ?? null,
            'price'      => $data['price'] ?? null,
            'attributes' => $data['attributes'] ?? null,
        ], fn($v) => !is_null($v)));

        return $variation;
    }
}
