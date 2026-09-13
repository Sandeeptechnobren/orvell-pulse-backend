<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('expenses')) {
            Schema::create('expenses', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('expense_number', 50)->unique();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
                $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('category', 50)->default('general'); // transport, labor, customs, utilities, general
                $table->decimal('amount', 10, 2);
                $table->string('currency', 5)->default('GHS');
                $table->text('description');
                $table->string('receipt_image')->nullable();
                $table->date('expense_date');
                $table->boolean('is_verified')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['company_id', 'expense_date']);
                $table->index(['staff_id', 'expense_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
