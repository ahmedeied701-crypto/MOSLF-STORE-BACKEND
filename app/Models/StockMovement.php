<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    public const UPDATED_AT = null; // Movements are immutable — no updates, ever.

    protected $fillable = [
        'inventory_id',
        'type',
        'quantity',
        'quantity_after',
        'reference',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'type'           => StockMovementType::class,
        'quantity'       => 'integer',
        'quantity_after' => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }


    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Business Logic ───────────────────────────────────────────────────────

    /**
     * The signed delta applied to inventory by this movement.
     * Positive = stock added, Negative = stock removed.
     */
    public function delta(): int
    {
        return $this->type->isAdditive()
            ? $this->quantity
            : -$this->quantity;
    }
}
