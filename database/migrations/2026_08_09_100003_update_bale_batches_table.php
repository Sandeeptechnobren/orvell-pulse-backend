<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bale_batches', function (Blueprint $table) {
            if (!Schema::hasColumn('bale_batches', 'uuid')) {
                $table->uuid('uuid')->nullable()->unique()->after('id');
            }
            if (!Schema::hasColumn('bale_batches', 'batch_code')) {
                $table->string('batch_code', 50)->nullable()->unique()->after('uuid');
            }
            if (!Schema::hasColumn('bale_batches', 'container_id')) {
                $table->foreignId('container_id')->nullable()->after('batch_code')->constrained('containers')->nullOnDelete();
            }
            if (!Schema::hasColumn('bale_batches', 'item_category_id')) {
                $table->foreignId('item_category_id')->nullable()->after('container_id')->constrained('item_category')->nullOnDelete();
            }
            if (!Schema::hasColumn('bale_batches', 'company_id')) {
                $table->foreignId('company_id')->nullable()->after('item_category_id')->constrained('companies')->nullOnDelete();
            }
            if (!Schema::hasColumn('bale_batches', 'total_quantity')) {
                $table->integer('total_quantity')->default(0)->after('company_id');
            }
            if (!Schema::hasColumn('bale_batches', 'remaining_quantity')) {
                $table->integer('remaining_quantity')->default(0)->after('total_quantity');
            }
            if (!Schema::hasColumn('bale_batches', 'unit_price')) {
                $table->decimal('unit_price', 10, 2)->default(0)->after('remaining_quantity');
            }
            if (!Schema::hasColumn('bale_batches', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bale_batches', function (Blueprint $table) {
            $columns = [
                'uuid', 'batch_code', 'container_id', 'item_category_id',
                'company_id', 'total_quantity', 'remaining_quantity',
                'unit_price', 'deleted_at'
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('bale_batches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
