<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('whatsapp_conversations')) {
            Schema::create('whatsapp_conversations', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('wa_id', 100)->index();
                $table->string('role', 30)->default('buyer'); // buyer, staff, cashier, admin
                $table->string('current_flow', 100)->nullable();
                $table->integer('current_step')->default(0);
                $table->json('state_payload')->nullable();
                $table->timestamp('last_interaction_at')->nullable();
                $table->timestamps();

                $table->index(['wa_id', 'role']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversations');
    }
};
