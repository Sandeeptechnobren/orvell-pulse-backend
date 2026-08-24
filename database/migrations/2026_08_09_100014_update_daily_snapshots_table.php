<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_snapshots', function (Blueprint $table) {
            if (!Schema::hasColumn('daily_snapshots', 'uuid')) {
                $table->uuid('uuid')->nullable()->unique()->after('id');
            }
            if (!Schema::hasColumn('daily_snapshots', 'company_id')) {
                $table->foreignId('company_id')->nullable()->after('uuid')->constrained('companies')->nullOnDelete();
            }
            if (!Schema::hasColumn('daily_snapshots', 'client_id')) {
                $table->foreignId('client_id')->nullable()->after('company_id')->constrained('clients')->nullOnDelete();
            }
            if (!Schema::hasColumn('daily_snapshots', 'snapshot_date')) {
                $table->date('snapshot_date')->nullable()->after('client_id');
            }
            if (!Schema::hasColumn('daily_snapshots', 'opening_stock_units')) {
                $table->integer('opening_stock_units')->default(0)->after('snapshot_date');
            }
            if (!Schema::hasColumn('daily_snapshots', 'opening_stock_value')) {
                $table->decimal('opening_stock_value', 12, 2)->default(0)->after('opening_stock_units');
            }
            if (!Schema::hasColumn('daily_snapshots', 'units_added')) {
                $table->integer('units_added')->default(0)->after('opening_stock_value');
            }
            if (!Schema::hasColumn('daily_snapshots', 'units_sold')) {
                $table->integer('units_sold')->default(0)->after('units_added');
            }
            if (!Schema::hasColumn('daily_snapshots', 'units_released')) {
                $table->integer('units_released')->default(0)->after('units_sold');
            }
            if (!Schema::hasColumn('daily_snapshots', 'units_adjusted')) {
                $table->integer('units_adjusted')->default(0)->after('units_released');
            }
            if (!Schema::hasColumn('daily_snapshots', 'closing_stock_units')) {
                $table->integer('closing_stock_units')->default(0)->after('units_adjusted');
            }
            if (!Schema::hasColumn('daily_snapshots', 'closing_stock_value')) {
                $table->decimal('closing_stock_value', 12, 2)->default(0)->after('closing_stock_units');
            }
            if (!Schema::hasColumn('daily_snapshots', 'category_breakdown')) {
                $table->json('category_breakdown')->nullable()->after('closing_stock_value');
            }
            if (!Schema::hasColumn('daily_snapshots', 'container_breakdown')) {
                $table->json('container_breakdown')->nullable()->after('category_breakdown');
            }

            $table->index(['company_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::table('daily_snapshots', function (Blueprint $table) {
            $columns = [
                'uuid', 'company_id', 'client_id', 'snapshot_date', 'opening_stock_units',
                'opening_stock_value', 'units_added', 'units_sold', 'units_released',
                'units_adjusted', 'closing_stock_units', 'closing_stock_value',
                'category_breakdown', 'container_breakdown'
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('daily_snapshots', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
