<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Inventory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\CartItem;

class ProductVariation extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'status',
        'sku',
        'price',
        'attributes', // JSON contains color, size, etc.
    ];

    protected $casts = [
        'attributes' => 'array',
        'price' => 'decimal:2',
    ];
    
    // ─── Relations Config ─────────────────────────

    protected const ADMIN_RELATIONS = [
        'images',
        'inventory',
    ];

    // ─── Relationships ────────────────────────────
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }
    public function canvasImages(): HasMany
    {
        return $this->hasMany(ProductImage::class)->where('type', 'canvas');
    }

    public function defaultImage()
    {
        return $this->hasOne(ProductImage::class)->where('is_default', true);
    }
    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class, 'product_variant_id');
    }

    public function inventory(): HasOne
    {
        return $this->hasOne(Inventory::class, 'product_variation_id');
    }
    public function getStockQuantityAttribute(): int
    {
        return $this->inventory?->quantity_on_hand ?? 0;
    }
    // ─── Scopes ───────────────────────────────────────────────────────────────
    public function scopeWithAdminRelations($query)
    {
        return $query->with(self::ADMIN_RELATIONS);
    }

    public function scopeActive($query): void
    {
        $query->where('status', 'active');
    }
    // ─── Loaders ──────────────────────────────────

    public function loadAdminRelations(): self
    {
        return $this->load(self::ADMIN_RELATIONS);
    }
}
