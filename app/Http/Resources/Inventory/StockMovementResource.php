<?php

declare(strict_types=1);

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'type'           => $this->type->value,
            'type_label'     => $this->type->label(),
            'direction'      => $this->type->isAdditive() ? 'in' : 'out',
            'quantity'       => $this->quantity,
            'delta'          => $this->delta(),     // Signed integer: +N or -N
            'quantity_after' => $this->quantity_after,
            'reference'      => $this->reference,
            'notes'          => $this->notes,
            'created_by'     => $this->whenLoaded(
                'creator',
                fn () => ['id' => $this->creator?->id, 'name' => $this->creator?->name]
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
