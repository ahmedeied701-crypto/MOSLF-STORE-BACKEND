<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $fillable = [
        'cart_id',
        'product_variant_id',
        'quantity',
        'custom_options'
    ];

    protected $casts = [
        'custom_options' => 'array',
        'quantity' => 'integer',
    ];

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'product_variant_id');
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }
}
