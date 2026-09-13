<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A customer is looked up with firstOrCreate(['wa_id' => …]). Without a unique index that is a
 * check-then-act: two webhook deliveries arriving together both miss the SELECT and both
 * INSERT, giving one person two customer records.
 *
 * The unique index closes it at the database, and lets Eloquent resolve the losing insert by
 * re-reading the winning row rather than duplicating.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Collapse any duplicates already stored, keeping the earliest row per wa_id so the
        // oldest buyer_id and any onboarding progress survive.
        $duplicates = DB::table('customers')
            ->select('wa_id', DB::raw('MIN(id) as keep_id'))
            ->whereNotNull('wa_id')
            ->groupBy('wa_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $row) {
            DB::table('customers')
                ->where('wa_id', $row->wa_id)
                ->where('id', '>', $row->keep_id)
                ->delete();
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_wa_id_index');
            $table->unique('wa_id', 'customers_wa_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_wa_id_unique');
            $table->index('wa_id', 'customers_wa_id_index');
        });
    }
};
