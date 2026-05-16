<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inventory_id')
                ->constrained('inventories')
                ->cascadeOnDelete();

            /**
             * Movement type drives all business logic.
             * Extensible: add 'invoice_deduction', 'custom_order', 'transfer' etc. later.
             *
             * Positive types: purchase, return, adjustment_add
             * Negative types: sale, adjustment_subtract, damage, expiry
             */
            $table->string('type')->comment('purchase|sale|return|adjustment_add|adjustment_subtract|damage|expiry');

            /**
             * Always stored as a positive integer.
             * Direction (add/subtract) is determined by `type`.
             * This avoids signed-integer confusion in reporting.
             */
            $table->integer('quantity')->unsigned();

            /**
             * Snapshot of stock AFTER this movement was applied.
             * Critical for auditing — lets you reconstruct history without replaying all events.
             */
            $table->integer('quantity_after')->comment('Stock level after this movement');

            $table->string('reference')->nullable()->comment('PO number, invoice id, adjustment ticket, etc.');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Indexes optimized for audit queries and reporting
            $table->index(['inventory_id', 'created_at']);
            $table->index(['inventory_id', 'type']);
            $table->index('reference');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
