<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('status')->default('active')->comment('active, inactive, archived');
            $table->string('sku')->unique();
            $table->decimal('price', 10, 2);
            // Flexible attributes (color, size, etc)
            $table->json('attributes')->nullable();
            // $table->string('color')->nullable();
            // $table->json('color_values')->nullable();
            // $table->string('size')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variations');
    }
};
