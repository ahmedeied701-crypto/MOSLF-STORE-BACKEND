<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Category extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'parent_id',
    ];

    /* ================= Relations ================= */

    // Parent Category
    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    // Child Categories
    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    // Products under this category
    public function products()
    {
        return $this->belongsToMany(Product::class);
    }

    // Collections related to this category
    public function collections()
    {
        return $this->belongsToMany(Collection::class);
    }
}
