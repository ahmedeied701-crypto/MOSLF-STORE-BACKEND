<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_variation_id',
        'image_path',
        'type',        // 'canvas' or 'gallery'
        'side',        // 'front', 'back', 'left', 'right'
        'is_default',
        'sort_order'
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $appends = ['url'];

    /**
     * Get the variation that owns the image.
     */
    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'product_variation_id');
    }

    /**
     * Full URL Accessor (recommended)
     * To use it: $image->url
     */
    public function getUrlAttribute(): string
    {
        return asset('storage/' . $this->image_path);
    }
}
