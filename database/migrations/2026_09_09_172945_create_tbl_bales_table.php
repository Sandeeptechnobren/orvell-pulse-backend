<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_bales', function (Blueprint $table) {
            $table->id();

            $table->string('bale_id', 50)->unique();

            $table->foreignId('container_id')
                ->constrained('tbl_containers')
                ->restrictOnDelete();

            $table->foreignId('supplier_id')
                ->constrained('tbl_suppliers')
                ->restrictOnDelete();

            $table->foreignId('category_id')
                ->constrained('tbl_categories')
                ->restrictOnDelete();

            $table->date('arrival_date');

            $table->string('status', 30)->default('available');

            $table->timestamps();

            $table->index('container_id');
            $table->index('supplier_id');
            $table->index('category_id');
            $table->index('arrival_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_bales');
    }
};