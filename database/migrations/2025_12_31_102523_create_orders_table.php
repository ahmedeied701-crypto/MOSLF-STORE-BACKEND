<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('order_number')->unique();
            $table->enum('status', ['pending','paid','shipped','delivered','cancelled'])->default('pending');
            $table->decimal('total', 10, 2);
            $table->string('payment_method')->nullable();
            $table->enum('payment_status', ['pending','completed','failed'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
