<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Inventory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductDemoSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Product
        $product = Product::create([
            'name' => 'Nike Air Max',
            'slug' => Str::slug('Nike Air Max'),
            'description' => 'Running shoes',
            'status' => 'active',
            'metadata' => [
                'brand' => 'Nike'
            ],
        ]);

        // 2. Variation
        $variation = ProductVariation::create([
            'product_id' => $product->id,
            'sku' => 'NIKE-AM-42-BLK',
            'price' => 2500,
            'attributes' => [
                'color' => 'black',
                'size' => '42',
            ],
        ]);

        // 3. Inventory (IMPORTANT)
        Inventory::create([
            'product_variation_id' => $variation->id,
            'quantity_on_hand' => 100,
            'reserved_quantity' => 0,
            'reorder_point' => 10,
            'reorder_quantity' => 50,
            'location' => 'warehouse-a',
        ]);
    }
}
