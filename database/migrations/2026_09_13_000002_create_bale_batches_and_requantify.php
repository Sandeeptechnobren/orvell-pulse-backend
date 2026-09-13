<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Quantity-based inventory: one row per container + category.
        Schema::create('tbl_bale_batches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('container_id')
                ->constrained('tbl_containers')
                ->restrictOnDelete();

            $table->foreignId('supplier_id')
                ->constrained('tbl_suppliers')
                ->restrictOnDelete();

            $table->foreignId('category_id')
                ->constrained('item_category')
                ->restrictOnDelete();

            $table->date('arrival_date');

            $table->unsignedInteger('qty_total')->default(0);
            $table->unsignedInteger('qty_available')->default(0);
            $table->unsignedInteger('qty_sold')->default(0);
            $table->unsignedInteger('qty_released')->default(0);
            $table->unsignedInteger('qty_damaged')->default(0);

            $table->timestamps();

            $table->unique(['container_id', 'category_id']);
            $table->index('category_id');
            $table->index('arrival_date');
        });

        // 2. Movements and invoice lines now reference batches with a quantity.
        Schema::table('tbl_stock_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('bale_id')->nullable()->change();
            $table->foreignId('bale_batch_id')
                ->nullable()
                ->after('bale_id')
                ->constrained('tbl_bale_batches')
                ->nullOnDelete();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            if (!Schema::hasColumn('invoice_items', 'bale_batch_id')) {
                $table->foreignId('bale_batch_id')
                    ->nullable()
                    ->after('bale_id')
                    ->constrained('tbl_bale_batches')
                    ->nullOnDelete();
            }
        });

        // 3. Convert existing per-bale rows into batch quantities.
        //    tbl_bales is retained untouched as a historical record.
        if (Schema::hasTable('tbl_bales')) {
            $groups = DB::table('tbl_bales')
                ->selectRaw("
                    container_id,
                    supplier_id,
                    category_id,
                    MIN(arrival_date) as arrival_date,
                    COUNT(*) as qty_total,
                    SUM(CASE WHEN status IN ('available', 'in_stock') THEN 1 ELSE 0 END) as qty_available,
                    SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as qty_sold,
                    SUM(CASE WHEN status = 'released' THEN 1 ELSE 0 END) as qty_released,
                    SUM(CASE WHEN status = 'damaged' THEN 1 ELSE 0 END) as qty_damaged
                ")
                ->groupBy('container_id', 'supplier_id', 'category_id')
                ->get();

            foreach ($groups as $group) {
                DB::table('tbl_bale_batches')->insert([
                    'container_id'  => $group->container_id,
                    'supplier_id'   => $group->supplier_id,
                    'category_id'   => $group->category_id,
                    'arrival_date'  => $group->arrival_date,
                    'qty_total'     => $group->qty_total,
                    'qty_available' => $group->qty_available,
                    'qty_sold'      => $group->qty_sold,
                    'qty_released'  => $group->qty_released,
                    'qty_damaged'   => $group->qty_damaged,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_items', 'bale_batch_id')) {
                $table->dropConstrainedForeignId('bale_batch_id');
            }
        });

        Schema::table('tbl_stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bale_batch_id');
        });

        Schema::dropIfExists('tbl_bale_batches');
    }
};
