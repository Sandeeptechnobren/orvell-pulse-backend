<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            if (!Schema::hasColumn('releases', 'uuid')) {
                $table->uuid('uuid')->nullable()->unique()->after('id');
            }
            if (!Schema::hasColumn('releases', 'release_code')) {
                $table->string('release_code', 50)->nullable()->unique()->after('uuid');
            }
            if (!Schema::hasColumn('releases', 'order_id')) {
                $table->foreignId('order_id')->nullable()->after('release_code')->constrained('orders')->nullOnDelete();
            }
            if (!Schema::hasColumn('releases', 'invoice_id')) {
                $table->foreignId('invoice_id')->nullable()->after('order_id')->constrained('invoices')->nullOnDelete();
            }
            if (!Schema::hasColumn('releases', 'buyer_id')) {
                $table->foreignId('buyer_id')->nullable()->after('invoice_id')->constrained('buyers')->nullOnDelete();
            }
            if (!Schema::hasColumn('releases', 'company_id')) {
                $table->foreignId('company_id')->nullable()->after('buyer_id')->constrained('companies')->nullOnDelete();
            }
            if (!Schema::hasColumn('releases', 'client_id')) {
                $table->foreignId('client_id')->nullable()->after('company_id')->constrained('clients')->nullOnDelete();
            }
            if (!Schema::hasColumn('releases', 'pickup_code')) {
                $table->string('pickup_code', 50)->nullable()->after('client_id');
            }
            if (!Schema::hasColumn('releases', 'released_by')) {
                $table->foreignId('released_by')->nullable()->after('pickup_code')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('releases', 'released_at')) {
                $table->timestamp('released_at')->nullable()->after('released_by');
            }
            if (!Schema::hasColumn('releases', 'status')) {
                $table->string('status', 30)->default('released')->after('released_at'); // released, partially_released
            }
            if (!Schema::hasColumn('releases', 'notes')) {
                $table->text('notes')->nullable()->after('status');
            }
            if (!Schema::hasColumn('releases', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }

            $table->index(['company_id', 'status', 'created_at']);
            $table->index(['pickup_code']);
        });
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $columns = [
                'uuid', 'release_code', 'order_id', 'invoice_id', 'buyer_id',
                'company_id', 'client_id', 'pickup_code', 'released_by',
                'released_at', 'status', 'notes', 'deleted_at'
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('releases', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
