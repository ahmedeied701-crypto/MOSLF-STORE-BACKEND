<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variation_id')
                ->constrained('product_variations')
                ->cascadeOnDelete();

            $table->integer('quantity_on_hand')->default(0)->comment('Computed from stock movements; never set directly');
            $table->integer('reserved_quantity')->default(0)->comment('Quantity reserved for pending orders');
            $table->integer('reorder_point')->default(10)->comment('Alert threshold for low stock');
            $table->integer('reorder_quantity')->default(0)->comment('Suggested restock amount');
            $table->string('location')->nullable()->comment('Warehouse/shelf location');
            $table->timestamps();

            $table->index('quantity_on_hand');
            $table->index('product_variation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
