<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Collection extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /* ================= Relations ================= */

    public function products()
    {
        return $this->belongsToMany(Product::class);
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class);
    }

    /* ================= Scopes ================= */

    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->where(fn ($q) =>
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now)
            )
            ->where(fn ($q) =>
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now)
            );
    }
}
