<?php

declare(strict_types=1);

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'quantity_on_hand'   => $this->quantity_on_hand,
            'reserved_quantity'  => $this->reserved_quantity,
            'available_quantity' => $this->availableQuantity(), // Business logic method
            'reorder_point'      => $this->reorder_point,
            'reorder_quantity'   => $this->reorder_quantity,
            'location'           => $this->location,
            'needs_restock'      => $this->needsRestock(),
            'updated_at'         => $this->updated_at?->toIso8601String(),

            // Lazy-load movement history only when explicitly requested
            'recent_movements' => $this->whenLoaded(
                'stockMovements',
                fn () => StockMovementResource::collection($this->stockMovements)
            ),
        ];
    }
}
