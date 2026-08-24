<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            if (!Schema::hasColumn('invoice_items', 'uuid')) {
                $table->uuid('uuid')->nullable()->unique()->after('id');
            }
            if (!Schema::hasColumn('invoice_items', 'invoice_id')) {
                $table->foreignId('invoice_id')->nullable()->after('uuid')->constrained('invoices')->cascadeOnDelete();
            }
            if (!Schema::hasColumn('invoice_items', 'bale_id')) {
                $table->foreignId('bale_id')->nullable()->after('invoice_id')->constrained('bales')->nullOnDelete();
            }
            if (!Schema::hasColumn('invoice_items', 'container_id')) {
                $table->foreignId('container_id')->nullable()->after('bale_id')->constrained('containers')->nullOnDelete();
            }
            if (!Schema::hasColumn('invoice_items', 'product_id')) {
                $table->foreignId('product_id')->nullable()->after('container_id')->constrained('products')->nullOnDelete();
            }
            if (!Schema::hasColumn('invoice_items', 'item_category_id')) {
                $table->foreignId('item_category_id')->nullable()->after('product_id')->constrained('item_category')->nullOnDelete();
            }
            if (!Schema::hasColumn('invoice_items', 'item_description')) {
                $table->string('item_description')->nullable()->after('item_category_id');
            }
            if (!Schema::hasColumn('invoice_items', 'quantity')) {
                $table->integer('quantity')->default(1)->after('item_description');
            }
            if (!Schema::hasColumn('invoice_items', 'unit_price')) {
                $table->decimal('unit_price', 10, 2)->default(0)->after('quantity');
            }
            if (!Schema::hasColumn('invoice_items', 'total_price')) {
                $table->decimal('total_price', 10, 2)->default(0)->after('unit_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $columns = [
                'uuid', 'invoice_id', 'bale_id', 'container_id', 'product_id',
                'item_category_id', 'item_description', 'quantity', 'unit_price',
                'total_price'
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('invoice_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
