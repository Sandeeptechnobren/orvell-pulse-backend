<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A conversation is looked up with firstOrCreate(['wa_id' => …]), which is a check-then-act:
 * two webhook deliveries arriving together both miss the SELECT and both INSERT. That is how
 * one inbound message produced two conversation rows for the same person.
 *
 * A unique index closes it at the database. Laravel's firstOrCreate catches the resulting
 * unique-constraint violation and re-reads the winning row, so the loser gets the existing
 * conversation instead of a duplicate — no application change required.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Collapse any duplicates already stored, keeping the earliest row for each wa_id.
        // Nothing references whatsapp_conversations.id — no foreign keys, and
        // whatsapp_messages has no conversation column — so the later rows can simply go.
        $duplicates = DB::table('whatsapp_conversations')
            ->select('wa_id', DB::raw('MIN(id) as keep_id'))
            ->whereNotNull('wa_id')
            ->groupBy('wa_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $row) {
            DB::table('whatsapp_conversations')
                ->where('wa_id', $row->wa_id)
                ->where('id', '>', $row->keep_id)
                ->delete();
        }

        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            // Redundant once wa_id is unique on its own.
            $table->dropIndex('whatsapp_conversations_wa_id_index');
            $table->unique('wa_id', 'whatsapp_conversations_wa_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropUnique('whatsapp_conversations_wa_id_unique');
            $table->index('wa_id', 'whatsapp_conversations_wa_id_index');
        });
    }
};
