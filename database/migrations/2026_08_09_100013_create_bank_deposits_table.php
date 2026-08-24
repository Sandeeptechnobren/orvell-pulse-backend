<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bank_deposits')) {
            Schema::create('bank_deposits', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('deposit_number', 50)->unique();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
                $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('bank_name', 100);
                $table->string('account_number', 50)->nullable();
                $table->decimal('amount', 10, 2);
                $table->string('currency', 5)->default('GHS');
                $table->string('reference_number', 100)->nullable();
                $table->string('deposit_slip_image')->nullable();
                $table->date('deposit_date');
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['company_id', 'deposit_date']);
                $table->index(['cashier_id', 'deposit_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_deposits');
    }
};
