<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_containers', function (Blueprint $table) {
            $table->id();

            $table->string('container_id', 50)->unique();

            $table->foreignId('supplier_id')
                ->constrained('tbl_suppliers')
                ->restrictOnDelete();

            $table->date('arrival_date');
            $table->date('received_date')->nullable();

            $table->string('status', 30)->default('pending');

            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index('supplier_id');
            $table->index('arrival_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_containers');
    }
};