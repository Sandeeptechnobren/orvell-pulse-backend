<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AgentDetails;
use App\Models\Customer;
use App\Models\Order;
use App\Services\ChatterlyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Paystack webhook — called by Paystack after a payment.
 * Verifies the HMAC signature, and on `charge.success` marks the order paid
 * and sends the customer a WhatsApp confirmation.
 *
 * Set this URL in the Paystack dashboard (Settings → API Keys & Webhooks):
 *   https://<public-url>/api/webhooks/paystack
 */
class PaystackWebhookController extends Controller
{
    public function handle(Request $request, ChatterlyService $chatterly): JsonResponse
    {
        $payload   = $request->getContent();
        $signature = (string) $request->header('x-paystack-signature');
        $secret    = (string) config('paystack.secret_key');

        // Verify the signature when a secret is configured.
        if ($secret !== '' && $signature !== '') {
            $computed = hash_hmac('sha512', $payload, $secret);
            if (! hash_equals($computed, $signature)) {
                Log::warning('[paystack-webhook] invalid signature');

                return response()->json(['ok' => false, 'error' => 'invalid signature'], 401);
            }
        }

        $event = (string) $request->input('event');
        $ref   = $request->input('data.reference');
        Log::info('[paystack-webhook] '.$event.' ref='.$ref);

        if ($event === 'charge.success' && $ref) {
            $order = Order::where('payment_reference', $ref)->first();

            if ($order && $order->payment_status !== 'paid') {
                $order->payment_status = 'paid';
                $order->status         = 'processing';
                $order->save();

                $customer = Customer::find($order->customer_id);
                if ($customer && $customer->whatsapp_number) {
                    $agent = AgentDetails::where('agent_type', 'customer')->whereNotNull('token')->latest('id')->first();
                    $chatterly->sendText($agent, (string) $customer->whatsapp_number,
                        "✅ *Payment received!*\nOrder #{$order->id} confirmed.\nAmount: ".config('paystack.currency', 'GHS').' '.number_format((float) $order->order_amount, 2).
                        "\nPickup code: *{$order->pickup_code}*\n\nThank you for shopping with Orvell Wholesale! 🎉");
                }
            }
        }

        return response()->json(['ok' => true]);
    }
}
