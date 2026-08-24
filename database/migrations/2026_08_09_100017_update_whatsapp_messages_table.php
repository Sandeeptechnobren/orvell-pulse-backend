<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('whatsapp_messages', 'uuid')) {
                $table->uuid('uuid')->nullable()->unique()->after('id');
            }
            if (!Schema::hasColumn('whatsapp_messages', 'company_id')) {
                $table->foreignId('company_id')->nullable()->after('uuid')->constrained('companies')->nullOnDelete();
            }
            if (!Schema::hasColumn('whatsapp_messages', 'client_id')) {
                $table->foreignId('client_id')->nullable()->after('company_id')->constrained('clients')->nullOnDelete();
            }
            if (!Schema::hasColumn('whatsapp_messages', 'sender_wa_id')) {
                $table->string('sender_wa_id', 100)->nullable()->index()->after('client_id');
            }
            if (!Schema::hasColumn('whatsapp_messages', 'recipient_wa_id')) {
                $table->string('recipient_wa_id', 100)->nullable()->index()->after('sender_wa_id');
            }
            if (!Schema::hasColumn('whatsapp_messages', 'direction')) {
                $table->string('direction', 20)->default('inbound')->after('recipient_wa_id'); // inbound, outbound
            }
            if (!Schema::hasColumn('whatsapp_messages', 'message_body')) {
                $table->text('message_body')->nullable()->after('direction');
            }
            if (!Schema::hasColumn('whatsapp_messages', 'message_type')) {
                $table->string('message_type', 30)->default('text')->after('message_body'); // text, image, document, template
            }
            if (!Schema::hasColumn('whatsapp_messages', 'media_url')) {
                $table->string('media_url')->nullable()->after('message_type');
            }
            if (!Schema::hasColumn('whatsapp_messages', 'chatterly_instance_id')) {
                $table->string('chatterly_instance_id')->nullable()->after('media_url');
            }
            if (!Schema::hasColumn('whatsapp_messages', 'status')) {
                $table->string('status', 30)->default('received')->after('chatterly_instance_id'); // received, sent, delivered, failed
            }
            if (!Schema::hasColumn('whatsapp_messages', 'idempotency_key')) {
                $table->string('idempotency_key')->nullable()->unique()->after('status');
            }

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $columns = [
                'uuid', 'company_id', 'client_id', 'sender_wa_id', 'recipient_wa_id',
                'direction', 'message_body', 'message_type', 'media_url',
                'chatterly_instance_id', 'status', 'idempotency_key'
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('whatsapp_messages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
