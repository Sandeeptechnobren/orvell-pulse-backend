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
        if (Schema::hasTable('agentprompt')) {
            Schema::table('agentprompt', function (Blueprint $table) {
                if (!Schema::hasColumn('agentprompt', 'prompt_for')) {
                    $table->string('prompt_for', 30)->default('customer')->after('client_id');
                }
            });

            // Add unique index on (client_id, prompt_for) if not exists
            Schema::table('agentprompt', function (Blueprint $table) {
                // Ensure index exists for quick lookup and uniqueness per client + prompt_for
                $table->index(['client_id', 'prompt_for']);
                $table->index(['user_id', 'prompt_for']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('agentprompt')) {
            Schema::table('agentprompt', function (Blueprint $table) {
                if (Schema::hasColumn('agentprompt', 'prompt_for')) {
                    $table->dropColumn('prompt_for');
                }
            });
        }
    }
};
