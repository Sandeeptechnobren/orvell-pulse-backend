<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained('conversations')
                ->cascadeOnDelete();

            $table->string('message_id')->unique();
            $table->string('sender')->index();

            $table->boolean('from_me')->default(false);
            $table->string('type')->nullable();
            $table->unsignedBigInteger('message_timestamp')->nullable();
            $table->string('source')->nullable();
            $table->string('chat_id')->nullable();

            $table->text('text')->nullable();
            $table->string('from_name')->nullable();

            $table->json('payload');

            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index([
                'conversation_id',
                'processed_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};