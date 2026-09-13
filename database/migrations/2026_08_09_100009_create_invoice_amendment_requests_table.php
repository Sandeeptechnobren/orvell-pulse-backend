<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('invoice_amendment_requests')) {
            Schema::create('invoice_amendment_requests', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
                $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
                $table->text('reason');
                $table->json('requested_changes'); // diff of items, quantities, prices
                $table->string('status', 30)->default('pending'); // pending, approved, rejected
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->foreignId('amended_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
                $table->timestamps();

                $table->index(['invoice_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_amendment_requests');
    }
};
