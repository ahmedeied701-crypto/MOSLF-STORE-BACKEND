<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\ProductVariation;
use App\Models\StockMovement;

class Inventory extends Model
{

    protected $attributes = [
        'reorder_point' => 10,
    ];

    protected $fillable = [
        'product_variation_id',
        'quantity_on_hand',
        'reserved_quantity',
        'reorder_point',
        'reorder_quantity',
        'location',
    ];

    protected $casts = [
        'quantity_on_hand'  => 'integer',
        'reserved_quantity' => 'integer',
        'reorder_point'     => 'integer',
        'reorder_quantity'  => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function productVariation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'product_variation_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'inventory_id');
    }

    // ─── Business Logic Helpers ───────────────────────────────────────────────

    /**
     * Available stock excludes reserved quantities.
     */
    public function availableQuantity(): int
    {
        return $this->quantity_on_hand - $this->reserved_quantity;
    }

    /**
     * Whether this inventory needs restocking based on reorder point.
     */
    public function needsRestock(): bool
    {
        return $this->reorder_point > 0
            && $this->quantity_on_hand <= $this->reorder_point;
    }

    /**
     * Check if a given quantity can be fulfilled from available stock.
     */
    public function canFulfill(int $quantity): bool
    {
        return $this->availableQuantity() >= $quantity;
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeLowStock($query): void
    {
        $query->whereColumn('quantity_on_hand', '<=', 'reorder_point')
            ->where('reorder_point', '>', 0);
    }

    public function scopeOutOfStock($query): void
    {
        $query->where('quantity_on_hand', '<=', 0);
    }
}
