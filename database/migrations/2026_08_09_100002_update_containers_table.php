<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('containers', function (Blueprint $table) {
            if (!Schema::hasColumn('containers', 'uuid')) {
                $table->uuid('uuid')->nullable()->unique()->after('id');
            }
            if (!Schema::hasColumn('containers', 'container_number')) {
                $table->string('container_number', 50)->nullable()->unique()->after('uuid');
            }
            if (!Schema::hasColumn('containers', 'supplier_name')) {
                $table->string('supplier_name')->nullable()->after('container_number');
            }
            if (!Schema::hasColumn('containers', 'company_id')) {
                $table->foreignId('company_id')->nullable()->after('supplier_name')->constrained('companies')->nullOnDelete();
            }
            if (!Schema::hasColumn('containers', 'client_id')) {
                $table->foreignId('client_id')->nullable()->after('company_id')->constrained('clients')->nullOnDelete();
            }
            if (!Schema::hasColumn('containers', 'space_id')) {
                $table->foreignId('space_id')->nullable()->after('client_id')->constrained('spaces')->nullOnDelete();
            }
            if (!Schema::hasColumn('containers', 'arrival_date')) {
                $table->date('arrival_date')->nullable()->after('space_id');
            }
            if (!Schema::hasColumn('containers', 'status')) {
                $table->string('status', 30)->default('in_stock')->after('arrival_date'); // docked, cleared, in_stock, depleted
            }
            if (!Schema::hasColumn('containers', 'total_bales')) {
                $table->integer('total_bales')->default(0)->after('status');
            }
            if (!Schema::hasColumn('containers', 'remaining_bales')) {
                $table->integer('remaining_bales')->default(0)->after('total_bales');
            }
            if (!Schema::hasColumn('containers', 'shipping_ref')) {
                $table->string('shipping_ref')->nullable()->after('remaining_bales');
            }
            if (!Schema::hasColumn('containers', 'notes')) {
                $table->text('notes')->nullable()->after('shipping_ref');
            }
            if (!Schema::hasColumn('containers', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('containers', function (Blueprint $table) {
            $columns = [
                'uuid', 'container_number', 'supplier_name', 'company_id',
                'client_id', 'space_id', 'arrival_date', 'status', 'total_bales',
                'remaining_bales', 'shipping_ref', 'notes', 'deleted_at'
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('containers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
