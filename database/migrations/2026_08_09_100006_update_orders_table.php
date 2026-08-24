<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'invoice_code')) {
                $table->string('invoice_code', 50)->nullable()->unique()->after('order_no');
            }
            if (!Schema::hasColumn('orders', 'buyer_id')) {
                $table->foreignId('buyer_id')->nullable()->after('invoice_code')->constrained('buyers')->nullOnDelete();
            }
            if (!Schema::hasColumn('orders', 'customer_id')) {
                $table->foreignId('customer_id')->nullable()->after('buyer_id')->constrained('customers')->nullOnDelete();
            }
            if (!Schema::hasColumn('orders', 'company_id')) {
                $table->foreignId('company_id')->nullable()->after('customer_id')->constrained('companies')->nullOnDelete();
            }
            if (!Schema::hasColumn('orders', 'client_id')) {
                $table->foreignId('client_id')->nullable()->after('company_id')->constrained('clients')->nullOnDelete();
            }
            if (!Schema::hasColumn('orders', 'space_id')) {
                $table->foreignId('space_id')->nullable()->after('client_id')->constrained('spaces')->nullOnDelete();
            }
            if (!Schema::hasColumn('orders', 'product_id')) {
                $table->foreignId('product_id')->nullable()->after('space_id')->constrained('products')->nullOnDelete();
            }
            if (!Schema::hasColumn('orders', 'container_id')) {
                $table->foreignId('container_id')->nullable()->after('product_id')->constrained('containers')->nullOnDelete();
            }
            if (!Schema::hasColumn('orders', 'salesperson_id')) {
                $table->foreignId('salesperson_id')->nullable()->after('container_id')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('orders', 'cashier_id')) {
                $table->foreignId('cashier_id')->nullable()->after('salesperson_id')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('orders', 'order_quantity')) {
                $table->integer('order_quantity')->default(1)->after('cashier_id');
            }
            if (!Schema::hasColumn('orders', 'order_amount')) {
                $table->decimal('order_amount', 10, 2)->default(0)->after('order_quantity');
            }
            if (!Schema::hasColumn('orders', 'currency')) {
                $table->string('currency', 5)->default('GHS')->after('order_amount');
            }
            if (!Schema::hasColumn('orders', 'payment_origin')) {
                $table->string('payment_origin')->nullable()->after('currency');
            }
            if (!Schema::hasColumn('orders', 'payment_status')) {
                $table->string('payment_status', 30)->default('pending')->after('payment_origin');
            }
            if (!Schema::hasColumn('orders', 'payment_method')) {
                $table->string('payment_method', 30)->nullable()->after('payment_status');
            }
            if (!Schema::hasColumn('orders', 'payment_reference')) {
                $table->string('payment_reference')->nullable()->after('payment_method');
            }
            if (!Schema::hasColumn('orders', 'pickup_code')) {
                $table->string('pickup_code', 50)->nullable()->after('payment_reference');
            }
            if (!Schema::hasColumn('orders', 'pickup_status')) {
                $table->string('pickup_status', 30)->default('pending')->after('pickup_code');
            }
            if (!Schema::hasColumn('orders', 'company_name')) {
                $table->string('company_name')->nullable()->after('pickup_status');
            }
            if (!Schema::hasColumn('orders', 'address')) {
                $table->text('address')->nullable()->after('company_name');
            }
            if (!Schema::hasColumn('orders', 'notes')) {
                $table->text('notes')->nullable()->after('address');
            }

            $table->index(['company_id', 'payment_status', 'created_at'], 'orders_comp_pay_created_idx');
            $table->index(['pickup_code']);
            $table->index(['payment_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $columns = [
                'invoice_code', 'buyer_id', 'customer_id', 'company_id', 'client_id',
                'space_id', 'product_id', 'container_id', 'salesperson_id', 'cashier_id',
                'order_quantity', 'order_amount', 'currency', 'payment_origin',
                'payment_status', 'payment_method', 'payment_reference', 'pickup_code',
                'pickup_status', 'company_name', 'address', 'notes'
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
