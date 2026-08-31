<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('sender');
            $table->string('channel_id');
            $table->timestamp('last_message_at')->nullable();
            $table->string('status')->default('active');
            $table->boolean('processing')->default(false);
            $table->timestamps();

            $table->unique(['sender', 'channel_id']);
            $table->index('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};