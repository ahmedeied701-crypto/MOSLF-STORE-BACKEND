<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();

            // Relationship to the specific variation (Color/Style)
            $table->foreignId('product_variation_id')
                ->constrained()
                ->cascadeOnDelete();

            // The file path in storage
            $table->string('image_path');

            /**
             * Image Purpose:
             * 'canvas'  -> Base images for the design engine (Fabric.js).
             * 'gallery' -> Lifestyle or display images for the storefront.
             */
            $table->enum('type', ['canvas', 'gallery'])->default('gallery');

            /**
             * Specific side for 'canvas' types: front, back, left, right.
             * Nullable for 'gallery' types.
             */
            $table->string('side')->nullable();

            // Metadata for display logic
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Indexes for Performance
            |--------------------------------------------------------------------------
            | 1. product_variation_id: Crucial for fetching images of a specific color.
            | 2. Composite (variation, type, side): Optimizes the design engine queries.
            */
            $table->index('product_variation_id');
            $table->index(['product_variation_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
