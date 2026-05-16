<?php

namespace App\Events;

use App\Models\Inventory;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InventoryUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $inventory;


    public function __construct(Inventory $inventory)
    {
        $this->inventory = $inventory->load('productVariation');
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('inventory-channel'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'inventory.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'inventory' => [
                'id'                   => $this->inventory->id,
                'product_id'           => $this->inventory->productVariation->product_id,
                'product_variation_id' => $this->inventory->product_variation_id,
                'quantity_on_hand'     => $this->inventory->quantity_on_hand,
                'updated_at'           => $this->inventory->updated_at,
            ]
        ];
    }
}
