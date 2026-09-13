<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bales', function (Blueprint $table) {
            if (!Schema::hasColumn('bales', 'uuid')) {
                $table->uuid('uuid')->nullable()->unique()->after('id');
            }
            if (!Schema::hasColumn('bales', 'bale_code')) {
                $table->string('bale_code', 50)->nullable()->unique()->after('uuid');
            }
            if (!Schema::hasColumn('bales', 'container_id')) {
                $table->foreignId('container_id')->nullable()->after('bale_code')->constrained('containers')->nullOnDelete();
            }
            if (!Schema::hasColumn('bales', 'bale_batch_id')) {
                $table->foreignId('bale_batch_id')->nullable()->after('container_id')->constrained('bale_batches')->nullOnDelete();
            }
            if (!Schema::hasColumn('bales', 'item_category_id')) {
                $table->foreignId('item_category_id')->nullable()->after('bale_batch_id')->constrained('item_category')->nullOnDelete();
            }
            if (!Schema::hasColumn('bales', 'company_id')) {
                $table->foreignId('company_id')->nullable()->after('item_category_id')->constrained('companies')->nullOnDelete();
            }
            if (!Schema::hasColumn('bales', 'client_id')) {
                $table->foreignId('client_id')->nullable()->after('company_id')->constrained('clients')->nullOnDelete();
            }
            if (!Schema::hasColumn('bales', 'supplier_name')) {
                $table->string('supplier_name')->nullable()->after('client_id');
            }
            if (!Schema::hasColumn('bales', 'arrival_date')) {
                $table->date('arrival_date')->nullable()->after('supplier_name');
            }
            if (!Schema::hasColumn('bales', 'cost_price')) {
                $table->decimal('cost_price', 10, 2)->default(0)->after('arrival_date');
            }
            if (!Schema::hasColumn('bales', 'selling_price')) {
                $table->decimal('selling_price', 10, 2)->default(0)->after('cost_price');
            }
            if (!Schema::hasColumn('bales', 'weight_kg')) {
                $table->decimal('weight_kg', 8, 2)->nullable()->after('selling_price');
            }
            if (!Schema::hasColumn('bales', 'status')) {
                $table->string('status', 30)->default('available')->after('weight_kg'); // available, reserved, sold, released, damaged
            }
            if (!Schema::hasColumn('bales', 'reserved_order_id')) {
                $table->foreignId('reserved_order_id')->nullable()->after('status')->constrained('orders')->nullOnDelete();
            }
            if (!Schema::hasColumn('bales', 'released_at')) {
                $table->timestamp('released_at')->nullable()->after('reserved_order_id');
            }
            if (!Schema::hasColumn('bales', 'released_by')) {
                $table->foreignId('released_by')->nullable()->after('released_at')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('bales', 'qr_code')) {
                $table->string('qr_code')->nullable()->after('released_by');
            }
            if (!Schema::hasColumn('bales', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }

            $table->index(['company_id', 'status']);
            $table->index(['container_id', 'status']);
            $table->index(['item_category_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('bales', function (Blueprint $table) {
            $columns = [
                'uuid', 'bale_code', 'container_id', 'bale_batch_id', 'item_category_id',
                'company_id', 'client_id', 'supplier_name', 'arrival_date', 'cost_price',
                'selling_price', 'weight_kg', 'status', 'reserved_order_id', 'released_at',
                'released_by', 'qr_code', 'deleted_at'
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('bales', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
