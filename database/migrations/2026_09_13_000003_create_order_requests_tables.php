<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_order_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('request_no', 30)->unique();

            $table->foreignId('customer_id')
                ->constrained('customers')
                ->restrictOnDelete();

            $table->string('status', 20)->default('pending'); // pending, converted, declined, cancelled
            $table->text('notes')->nullable();
            $table->text('declined_reason')->nullable();

            $table->foreignId('actioned_by')->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('actioned_at')->nullable();

            $table->foreignId('order_id')->nullable()
                ->constrained('orders')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('customer_id');
        });

        Schema::create('tbl_order_request_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_request_id')
                ->constrained('tbl_order_requests')
                ->cascadeOnDelete();

            $table->foreignId('category_id')
                ->constrained('item_category')
                ->restrictOnDelete();

            $table->unsignedInteger('quantity');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_order_request_items');
        Schema::dropIfExists('tbl_order_requests');
    }
};
