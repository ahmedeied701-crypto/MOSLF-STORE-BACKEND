<?php

// =============================================================================
// app/Services/Cart/StockService.php
// =============================================================================

namespace App\Domain\Cart\Services;

use App\Models\ProductVariation;

class StockService
{
    /**
     * Clamp a requested quantity against a locked variant's stock.
     * Returns null when the variant is out of stock.
     * Variant MUST already be locked before this is called.
     */
    public function clampQty(ProductVariation $variant, int $requested): ?int
    {
        if ($variant->stock < 1) {
            return null;
        }

        return min(max(1, $requested), $variant->stock);
    }
}
