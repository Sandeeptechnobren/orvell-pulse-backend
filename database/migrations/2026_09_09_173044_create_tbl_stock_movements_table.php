<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_stock_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bale_id')
                ->constrained('tbl_bales')
                ->restrictOnDelete();

            $table->foreignId('container_id')
                ->constrained('tbl_containers')
                ->restrictOnDelete();

            $table->string('movement_type', 50);

            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->integer('quantity');

            $table->timestamp('movement_date');

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('bale_id');
            $table->index('container_id');
            $table->index('movement_type');
            $table->index(['reference_type', 'reference_id']);
            $table->index('movement_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_stock_movements');
    }
};