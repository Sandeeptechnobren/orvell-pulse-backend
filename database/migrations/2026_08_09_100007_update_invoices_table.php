<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'uuid')) {
                $table->uuid('uuid')->nullable()->unique()->after('id');
            }
            if (!Schema::hasColumn('invoices', 'invoice_number')) {
                $table->string('invoice_number', 50)->nullable()->unique()->after('uuid');
            }
            if (!Schema::hasColumn('invoices', 'original_invoice_id')) {
                $table->foreignId('original_invoice_id')->nullable()->after('invoice_number')->constrained('invoices')->nullOnDelete();
            }
            if (!Schema::hasColumn('invoices', 'amendment_version')) {
                $table->string('amendment_version', 5)->nullable()->after('original_invoice_id'); // A, B, C...
            }
            if (!Schema::hasColumn('invoices', 'order_id')) {
                $table->foreignId('order_id')->nullable()->after('amendment_version')->constrained('orders')->nullOnDelete();
            }
            if (!Schema::hasColumn('invoices', 'buyer_id')) {
                $table->foreignId('buyer_id')->nullable()->after('order_id')->constrained('buyers')->nullOnDelete();
            }
            if (!Schema::hasColumn('invoices', 'company_id')) {
                $table->foreignId('company_id')->nullable()->after('buyer_id')->constrained('companies')->nullOnDelete();
            }
            if (!Schema::hasColumn('invoices', 'client_id')) {
                $table->foreignId('client_id')->nullable()->after('company_id')->constrained('clients')->nullOnDelete();
            }
            if (!Schema::hasColumn('invoices', 'subtotal')) {
                $table->decimal('subtotal', 10, 2)->default(0)->after('client_id');
            }
            if (!Schema::hasColumn('invoices', 'tax_amount')) {
                $table->decimal('tax_amount', 10, 2)->default(0)->after('subtotal');
            }
            if (!Schema::hasColumn('invoices', 'discount_amount')) {
                $table->decimal('discount_amount', 10, 2)->default(0)->after('tax_amount');
            }
            if (!Schema::hasColumn('invoices', 'total_amount')) {
                $table->decimal('total_amount', 10, 2)->default(0)->after('discount_amount');
            }
            if (!Schema::hasColumn('invoices', 'paid_amount')) {
                $table->decimal('paid_amount', 10, 2)->default(0)->after('total_amount');
            }
            if (!Schema::hasColumn('invoices', 'payment_status')) {
                $table->string('payment_status', 30)->default('unpaid')->after('paid_amount'); // unpaid, partial, paid
            }
            if (!Schema::hasColumn('invoices', 'status')) {
                $table->string('status', 30)->default('finalized')->after('payment_status'); // draft, finalized, amended, cancelled
            }
            if (!Schema::hasColumn('invoices', 'issued_date')) {
                $table->date('issued_date')->nullable()->after('status');
            }
            if (!Schema::hasColumn('invoices', 'due_date')) {
                $table->date('due_date')->nullable()->after('issued_date');
            }
            if (!Schema::hasColumn('invoices', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('due_date')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('invoices', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('invoices', 'notes')) {
                $table->text('notes')->nullable()->after('approved_by');
            }
            if (!Schema::hasColumn('invoices', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }

            $table->index(['company_id', 'status', 'created_at']);
            $table->index(['buyer_id', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $columns = [
                'uuid', 'invoice_number', 'original_invoice_id', 'amendment_version',
                'order_id', 'buyer_id', 'company_id', 'client_id', 'subtotal',
                'tax_amount', 'discount_amount', 'total_amount', 'paid_amount',
                'payment_status', 'status', 'issued_date', 'due_date', 'created_by',
                'approved_by', 'notes', 'deleted_at'
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
