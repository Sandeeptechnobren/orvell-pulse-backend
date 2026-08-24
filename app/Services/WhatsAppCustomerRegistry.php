<?php

namespace App\Services;

use App\Models\AgentDetails;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;

class WhatsAppCustomerRegistry
{
    /**
     * Store the sender as a customer on first contact. Safe against concurrent
     * deliveries because customers.wa_id is unique.
     */
    public function rememberSender(string $waId, ?AgentDetails $agent = null): ?Customer
    {
        $waId = trim($waId);

        if ($waId === '') {
            return null;
        }

        try {
            $customer = Customer::firstOrCreate(
                ['wa_id' => $waId],
                [
                    'whatsapp_number'   => self::phoneFrom($waId),
                    'name'              => $this->placeholderName($waId),
                    'address'           => 'WhatsApp',
                    'onboarding_status' => 0,
                ]
            );

            if ($customer->wasRecentlyCreated) {
                Log::info('[wa-customer] registered new sender', [
                    'wa_id'    => $waId,
                    'customer' => $customer->id,
                    'instance' => $agent?->name,
                ]);
            }

            return $customer;
        } catch (\Throwable $e) {
            Log::warning('[wa-customer] could not register sender', [
                'wa_id' => $waId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Only a @c.us address carries a real number. A @lid is a privacy id whose digits
     * cannot be dialled, so those return null rather than a convincing fake.
     */
    public static function phoneFrom(?string $waId): ?string
    {
        $waId = trim((string) $waId);

        if ($waId === '') {
            return null;
        }

        if (str_contains($waId, '@') && ! str_ends_with($waId, '@c.us')) {
            return null;
        }

        $local  = explode(':', explode('@', $waId)[0])[0];
        $digits = preg_replace('/\D+/', '', $local) ?? '';

        return $digits !== '' ? $digits : null;
    }

    private function placeholderName(string $waId): string
    {
        $digits = preg_replace('/\D+/', '', $waId) ?? '';

        return 'WhatsApp '.($digits !== '' ? substr($digits, -4) : 'Contact');
    }
}
