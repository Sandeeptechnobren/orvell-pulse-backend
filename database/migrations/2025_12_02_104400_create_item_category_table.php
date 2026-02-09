<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('item_category', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->string('code')->unique();
    $table->string('category_name');
    $table->string('category_type');
    $table->unsignedBigInteger('created_by')->nullable();
    $table->unsignedBigInteger('updated_by')->nullable();
    $table->softDeletes();
    $table->timestamps();
    
    $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
    $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_category');
    }
};
