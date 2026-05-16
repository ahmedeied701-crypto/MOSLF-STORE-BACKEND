<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Inventory;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * UpdateStockAction
 *
 * The ONLY authorized path for modifying inventory quantities.
 * Enforces: atomic updates, signed-delta calculation, audit trail, and stock validation.
 *
 * Architecture note:
 *   quantity_on_hand is NEVER written directly — it is always derived
 *   from this action, which records the movement and updates the ledger atomically.
 *   This gives us a full, tamper-evident audit log for free.
 */
final class UpdateStockAction
{
    /**
     * @param  array{
     *     type: StockMovementType,
     *     quantity: int,
     *     reference?: ?string,
     *     notes?: ?string,
     * } $data
     *
     * @throws InsufficientStockException
     */
    public function execute(Inventory $inventory, array $data): StockMovement
    {
        return DB::transaction(function () use ($inventory, $data): StockMovement {

            // Lock the inventory row to prevent concurrent race conditions.
            // Without this, two simultaneous sales could both pass the stock check.
            /** @var Inventory $inventory */
            $inventory = Inventory::lockForUpdate()->findOrFail($inventory->id);

            $type     = $data['type'];

            // Always work with positive quantities
            if ($data['quantity'] <= 0) {
                throw new \InvalidArgumentException('Quantity must be greater than zero.');
            }

            $quantity = $data['quantity'];

            // Guard: Prevent subtractive movements from going below zero
            if ($type->isSubtractive() && $inventory->quantity_on_hand < $quantity) {
                throw new InsufficientStockException(
                    available: $inventory->quantity_on_hand,
                    requested: $quantity,
                );
            }



            // if (!$inventory->product_id) {
            //     throw new \LogicException('Inventory is not linked to a product.');
            // }

            // Apply delta based on movement direction
            $newQuantity = $type->isAdditive()
                ? $inventory->quantity_on_hand + $quantity
                : $inventory->quantity_on_hand - $quantity;

            // Update the ledger
            $inventory->update(['quantity_on_hand' => $newQuantity]);

            // Record the immutable movement (the audit trail)
            $movement = StockMovement::create([
                'inventory_id'   => $inventory->id,
                'product_id'     => $inventory->product_id,
                'type'           => $type,
                'quantity'       => $quantity,
                'quantity_after' => $newQuantity,
                'reference'      => $data['reference'] ?? null,
                'notes'          => $data['notes'] ?? null,
                'created_by' => Auth::id() ?? 0,
            ]);

            return $movement->load(['inventory.productVariation.product']);
        });
    }
}
