<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Single source of truth for bale categories: item_category.
        //    tbl_categories was never seeded; item_category holds CAT-001... and
        //    is what the AI tools and category APIs already use.
        if (Schema::hasTable('tbl_bales')) {
            Schema::table('tbl_bales', function (Blueprint $table) {
                $table->dropForeign(['category_id']);
                $table->foreign('category_id')
                    ->references('id')
                    ->on('item_category')
                    ->restrictOnDelete();
            });
        }

        // 2. Sales are made to customers (chat-registered BUY001...), not the
        //    legacy buyers table. buyer_id stays nullable for old rows.
        if (Schema::hasTable('invoices') && !Schema::hasColumn('invoices', 'customer_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->foreignId('customer_id')
                    ->nullable()
                    ->after('buyer_id')
                    ->constrained('customers')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tbl_bales')) {
            Schema::table('tbl_bales', function (Blueprint $table) {
                $table->dropForeign(['category_id']);
                $table->foreign('category_id')
                    ->references('id')
                    ->on('tbl_categories')
                    ->restrictOnDelete();
            });
        }

        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'customer_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropConstrainedForeignId('customer_id');
            });
        }
    }
};
