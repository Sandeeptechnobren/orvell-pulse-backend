<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'uuid')) {
                $table->uuid('uuid')->nullable()->unique()->after('id');
            }
            if (!Schema::hasColumn('payments', 'payment_number')) {
                $table->string('payment_number', 50)->nullable()->unique()->after('uuid');
            }
            if (!Schema::hasColumn('payments', 'invoice_id')) {
                $table->foreignId('invoice_id')->nullable()->after('payment_number')->constrained('invoices')->nullOnDelete();
            }
            if (!Schema::hasColumn('payments', 'order_id')) {
                $table->foreignId('order_id')->nullable()->after('invoice_id')->constrained('orders')->nullOnDelete();
            }
            if (!Schema::hasColumn('payments', 'buyer_id')) {
                $table->foreignId('buyer_id')->nullable()->after('order_id')->constrained('buyers')->nullOnDelete();
            }
            if (!Schema::hasColumn('payments', 'company_id')) {
                $table->foreignId('company_id')->nullable()->after('buyer_id')->constrained('companies')->nullOnDelete();
            }
            if (!Schema::hasColumn('payments', 'client_id')) {
                $table->foreignId('client_id')->nullable()->after('company_id')->constrained('clients')->nullOnDelete();
            }
            if (!Schema::hasColumn('payments', 'payment_method')) {
                $table->string('payment_method', 30)->default('cash')->after('amount'); // cash, bank_transfer, paystack, pos, momo
            }
            if (!Schema::hasColumn('payments', 'payment_reference')) {
                $table->string('payment_reference')->nullable()->unique()->after('payment_method');
            }
            if (!Schema::hasColumn('payments', 'payment_proof_image')) {
                $table->string('payment_proof_image')->nullable()->after('payment_reference');
            }
            if (!Schema::hasColumn('payments', 'cashier_id')) {
                $table->foreignId('cashier_id')->nullable()->after('payment_proof_image')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('payments', 'notes')) {
                $table->text('notes')->nullable()->after('cashier_id');
            }
            if (!Schema::hasColumn('payments', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }

            $table->index(['company_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $columns = [
                'uuid', 'payment_number', 'invoice_id', 'order_id', 'buyer_id',
                'company_id', 'client_id', 'payment_method', 'payment_reference',
                'payment_proof_image', 'cashier_id', 'notes', 'deleted_at'
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
