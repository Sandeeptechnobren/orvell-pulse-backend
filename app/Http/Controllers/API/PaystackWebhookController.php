<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AgentDetails;
use App\Models\Customer;
use App\Models\Buyer;
use App\Services\PaymentService;
use App\Services\ChatterlyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * @OA\Tag(
 *     name="Paystack Webhook",
 *     description="Paystack HMAC verified payment webhook handler"
 * )
 */
class PaystackWebhookController extends Controller
{
    protected PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Process Paystack Inbound Webhook
     *
     * @OA\Post(
     *     path="/api/webhooks/paystack",
     *     tags={"Paystack Webhook"},
     *     summary="Inbound Paystack webhook for automated payment settlement",
     *     @OA\Parameter(
     *         name="x-paystack-signature",
     *         in="header",
     *         description="HMAC SHA512 signature of request payload computed using secret key",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"event", "data"},
     *             @OA\Property(property="event", type="string", example="charge.success"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="reference", type="string", example="PAYSTACK-REF-123456"),
     *                 @OA\Property(property="amount", type="integer", example=35000),
     *                 @OA\Property(property="currency", type="string", example="GHS"),
     *                 @OA\Property(property="status", type="string", example="success"),
     *                 @OA\Property(
     *                     property="metadata",
     *                     type="object",
     *                     @OA\Property(property="order_id", type="integer", example=1),
     *                     @OA\Property(property="invoice_id", type="integer", example=1)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="Webhook processed successfully"),
     *     @OA\Response(response=400, description="Invalid HMAC signature or validation error"),
     *     @OA\Response(response=500, description="Server processing error")
     * )
     */
    public function handle(Request $request, ChatterlyService $chatterly): JsonResponse
    {
        $rawPayload = $request->getContent();
        $signature  = (string) $request->header('x-paystack-signature');
        $payloadData = $request->all();

        try {
            $result = $this->paymentService->handlePaystackWebhook($rawPayload, $signature, $payloadData);

            // Optional WhatsApp notification if order exists and payment is verified
            if (!empty($result['order'])) {
                $order = $result['order'];
                $buyerPhone = $order->buyer?->whatsapp_number ?? $order->customer?->whatsapp_number;

                if ($buyerPhone) {
                    $agent = AgentDetails::where('agent_type', 'customer')->whereNotNull('token')->latest('id')->first();
                    if ($agent) {
                        $chatterly->sendText(
                            $agent,
                            (string) $buyerPhone,
                            "✅ *Payment received!*\nOrder #{$order->order_no} confirmed.\nAmount: ".($order->currency ?? 'GHS').' '.number_format((float) $order->total_amount, 2).
                            "\nPickup code: *{$order->pickup_code}*\n\nThank you for shopping with Orvell Wholesale! 🎉"
                        );
                    }
                }
            }

            return response()->json(['ok' => true, 'result' => $result]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->errors()], 400);
        } catch (\Throwable $e) {
            Log::error('[paystack-webhook] Error processing webhook: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
