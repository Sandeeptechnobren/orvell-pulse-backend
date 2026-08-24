<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('inventory_transactions')) {
            Schema::create('inventory_transactions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
                $table->foreignId('container_id')->nullable()->constrained('containers')->nullOnDelete();
                $table->foreignId('bale_id')->nullable()->constrained('bales')->nullOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->foreignId('item_category_id')->nullable()->constrained('item_category')->nullOnDelete();
                $table->string('transaction_type', 40); // container_arrival, bale_addition, sale_reservation, sale_deduction, pickup_release, adjustment_addition, adjustment_deduction, return
                $table->integer('quantity'); // + or -
                $table->decimal('unit_price', 10, 2)->nullable();
                $table->string('reference_type')->nullable(); // Order, Invoice, Release, Container, Adjustment
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'transaction_type', 'created_at'], 'inv_tx_comp_type_created_idx');
                $table->index(['container_id', 'created_at'], 'inv_tx_cont_created_idx');
                $table->index(['bale_id', 'created_at'], 'inv_tx_bale_created_idx');
                $table->index(['reference_type', 'reference_id'], 'inv_tx_ref_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
    }
};
