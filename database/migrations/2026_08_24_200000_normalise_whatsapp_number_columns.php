<?php

use App\Services\WhatsAppCustomerRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `whatsapp_number` was storing the WhatsApp address rather than the phone number —
 * "233200111222@c.us", or worse "43835006668981@lid", which is not a number at all.
 *
 * After this migration the two columns mean distinct things:
 *   wa_id           — the full chat address, used to send messages
 *   whatsapp_number — a dialable phone number, or NULL when we genuinely don't know it
 *
 * A @lid is WhatsApp's privacy identifier: its digits resemble a phone number but cannot be
 * dialled and rotate over time. Those rows get NULL rather than a plausible-looking fake,
 * because this column is what staff will ring.
 *
 * wa_id is populated first wherever it is empty, so nulling the number never costs us the
 * ability to reach that person.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers', 'buyers'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                $address = $row->wa_id ?: $row->whatsapp_number;

                if (! $address) {
                    continue;
                }

                DB::table($table)->where('id', $row->id)->update([
                    'wa_id'           => $address,
                    'whatsapp_number' => WhatsAppCustomerRegistry::phoneFrom($address),
                ]);
            }
        }
    }

    /**
     * wa_id retains the original address, so the previous (incorrect) value of
     * whatsapp_number can be restored exactly.
     */
    public function down(): void
    {
        foreach (['customers', 'buyers'] as $table) {
            DB::table($table)->whereNotNull('wa_id')->update([
                'whatsapp_number' => DB::raw('wa_id'),
            ]);
        }
    }
};
