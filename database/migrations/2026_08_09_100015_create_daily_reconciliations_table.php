<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('daily_reconciliations')) {
            Schema::create('daily_reconciliations', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
                $table->date('reconciliation_date');
                $table->decimal('total_sales_amount', 12, 2)->default(0);
                $table->decimal('total_cash_collected', 12, 2)->default(0);
                $table->decimal('total_bank_deposits', 12, 2)->default(0);
                $table->decimal('total_expenses', 12, 2)->default(0);
                $table->integer('pending_invoices_count')->default(0);
                $table->decimal('pending_invoices_amount', 12, 2)->default(0);
                $table->integer('stock_variance_units')->default(0);
                $table->string('status', 30)->default('balanced'); // balanced, discrepancy_found
                $table->json('discrepancies')->nullable();
                $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['company_id', 'reconciliation_date'], 'daily_rec_comp_date_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_reconciliations');
    }
};
