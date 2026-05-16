<?php

declare(strict_types=1);

namespace App\Actions\Product\Delete;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * DeleteProductAction
 *
 * Soft-deletes a product. The inventory ledger and stock movement history
 * are intentionally preserved for financial auditing purposes.
 */
final class DeleteProductAction
{
    public function execute(Product $product): void
    {
        DB::transaction(function () use ($product): void {

            // Mark all variations as archived first
            $product->variations()->update([
                'status' => 'archived',
            ]);

            // Mark product as archived before deleting to preserve status history
            $product->update(['status' => 'archived']);
            
            $product->delete(); // Soft delete — data is never truly removed
        });
    }
}
