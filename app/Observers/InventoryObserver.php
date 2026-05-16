<?php

namespace App\Observers;

use App\Models\Inventory;
use App\Events\InventoryUpdated;

class InventoryObserver
{
    public function updated(Inventory $inventory): void
    {
        broadcast(new InventoryUpdated($inventory));
    }


    public function created(Inventory $inventory): void
    {
        broadcast(new InventoryUpdated($inventory));
    }

    /**
     * Handle the Inventory "deleted" event.
     */
    public function deleted(Inventory $inventory): void
    {
        //
    }

    /**
     * Handle the Inventory "restored" event.
     */
    public function restored(Inventory $inventory): void
    {
        //
    }

    /**
     * Handle the Inventory "force deleted" event.
     */
    public function forceDeleted(Inventory $inventory): void
    {
        //
    }
}
