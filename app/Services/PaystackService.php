<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Paystack payment gateway.
 *  - initialize(): create a transaction → returns the hosted checkout URL + reference.
 *  - verify():     confirm a transaction reference after payment.
 * Always graceful (never throws); returns null on failure.
 */
class PaystackService
{
    private function secret(): ?string
    {
        return config('paystack.secret_key');
    }

    private function base(): string
    {
        return rtrim((string) config('paystack.base_url', 'https://api.paystack.co'), '/');
    }

    /** Initialize a transaction. Returns ['url' => ..., 'reference' => ...] or null. */
    public function initialize(float $amount, string $email, string $reference, array $meta = []): ?array
    {
        $secret = $this->secret();
        if (! $secret) {
            Log::warning('[paystack] no secret key configured');

            return null;
        }

        try {
            $payload = [
                'email'     => $email,
                'amount'    => (int) round($amount * 100), // smallest unit (pesewas / kobo)
                'currency'  => config('paystack.currency', 'GHS'),
                'reference' => $reference,
                'metadata'  => $meta,
            ];
            if ($cb = config('paystack.callback_url')) {
                $payload['callback_url'] = $cb;
            }

            $resp = Http::withToken($secret)->timeout(15)
                ->post($this->base().'/transaction/initialize', $payload);

            if ($resp->successful() && $resp->json('status')) {
                return [
                    'url'       => $resp->json('data.authorization_url'),
                    'reference' => $resp->json('data.reference'),
                ];
            }

            Log::warning('[paystack] initialize failed', ['body' => mb_substr($resp->body(), 0, 300)]);

            return null;
        } catch (\Throwable $e) {
            Log::error('[paystack] initialize error: '.$e->getMessage());

            return null;
        }
    }

    /** Verify a transaction reference. Returns the data array (status, amount, ...) or null. */
    public function verify(string $reference): ?array
    {
        $secret = $this->secret();
        if (! $secret) {
            return null;
        }

        try {
            $resp = Http::withToken($secret)->timeout(15)
                ->get($this->base().'/transaction/verify/'.urlencode($reference));

            if ($resp->successful() && $resp->json('status')) {
                return $resp->json('data');
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('[paystack] verify error: '.$e->getMessage());

            return null;
        }
    }
}
