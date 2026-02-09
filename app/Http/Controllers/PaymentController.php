<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Stripe\Stripe;
use Stripe\Charge;
use App\Models\Payment;

/**
 * @OA\Tag(
 *     name="Payment",
 *     description="Stripe payment APIs"
 * )
 */
class PaymentController extends Controller
{
    /**
     * Payment Page (Web)
     *
     * @OA\Get(
     *     path="/payment",
     *     tags={"Payment"},
     *     summary="Payment page",
     *     description="Returns payment view (web only)",
     *     @OA\Response(
     *         response=200,
     *         description="Payment page loaded"
     *     )
     * )
     */
    public function index()
    {
        return view('payment');
    }

    /**
     * Create Stripe Payment
     *
     * @OA\Post(
     *     path="/api/payment",
     *     tags={"Payment"},
     *     summary="Create payment",
     *     description="Make payment using Stripe",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"amount","stripeToken"},
     *             @OA\Property(property="amount", type="number", example=500),
     *             @OA\Property(property="stripeToken", type="string", example="tok_visa"),
     *             @OA\Property(property="email", type="string", example="user@example.com")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Payment successful",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Payment successful"),
     *             @OA\Property(
     *                 property="data",
     *                 ref="#/components/schemas/Payment"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Payment failed"
     *     )
     * )
     */
    public function payment(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'stripeToken' => 'required|string',
            'email' => 'nullable|email'
        ]);

        Stripe::setApiKey(env('STRIPE_SECRET'));

        $charge = Charge::create([
            'amount'      => $request->amount * 100,
            'currency'    => 'inr',
            'source'      => $request->stripeToken,
            'description' => 'Test Payment'
        ]);

        $payment = Payment::create([
            'payment_id'     => $charge->id,
            'customer_email' => $request->email ?? null,
            'amount'         => $request->amount,
            'currency'       => 'INR',
            'status'         => $charge->status,
            'response'       => json_encode($charge)
        ]);

        return response()->json([
            'message' => 'Payment successful',
            'data'    => $payment
        ]);
    }

    /**
     * Payment History
     *
     * @OA\Get(
     *     path="/api/payment-history",
     *     tags={"Payment"},
     *     summary="Payment history",
     *     description="Get all payment records",
     *     @OA\Response(
     *         response=200,
     *         description="Payment list",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(ref="#/components/schemas/Payment")
     *         )
     *     )
     * )
     */
    public function paymentHistoryy()
    {
        $payments = Payment::orderBy('created_at', 'asc')->get();

        return response()->json($payments);
    }
}
