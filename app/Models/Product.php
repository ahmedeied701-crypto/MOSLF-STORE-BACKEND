<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'deleted_at' => 'datetime',
    ];

    // ─── Relations Config ─────────────────────────

    protected const ADMIN_RELATIONS = [
        'variations.images',
        'variations.inventory',
    ];

    // ─── Relationships ────────────────────────────

    public function variations(): HasMany
    {
        return $this->hasMany(ProductVariation::class);
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

    public function scopeSearch($query, string $term): void
    {
        $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%");
        });
    }

    // ─── Loaders ──────────────────────────────────

    public function loadAdminRelations(): self
    {
        return $this->load(self::ADMIN_RELATIONS);
    }

    // ─── Lifecycle Hooks ──────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (empty($product->slug)) {
                $product->slug = Str::slug($product->name);
            }
        });
    }
}
