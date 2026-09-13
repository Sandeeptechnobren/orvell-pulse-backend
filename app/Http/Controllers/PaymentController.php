<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Payment;
use App\Services\PaymentService;
use Stripe\Stripe;
use Stripe\Charge;

class PaymentController extends Controller
{
    protected PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function recordCash(Request $request)
    {
        $request->validate([
            'invoice_id'          => 'nullable|exists:invoices,id',
            'invoice_number'      => 'nullable|string',
            'order_id'            => 'nullable|exists:orders,id',
            'amount'              => 'required|numeric|min:0.01',
            'payment_reference'   => 'nullable|string',
            'payment_proof_image' => 'nullable|string',
            'notes'               => 'nullable|string',
        ]);

        $user = auth()->user();

        // RBAC: Verify user has Cashier or Admin privileges
        if ($user instanceof \App\Models\User) {
            $isAuthorized = false;

            if (method_exists($user, 'hasRole')) {
                $isAuthorized = $user->hasRole(['Cashier', 'cashier', 'Admin', 'admin', 'Super Admin', 'super-admin']);
            }

            if (!$isAuthorized && method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['Salesperson', 'salesperson', 'Staff', 'staff'])) {
                app(\App\Services\AuditService::class)->log(
                    action: 'auth.access_denied',
                    auditable: $user,
                    newValues: ['endpoint' => '/api/payment/cash', 'reason' => 'Unauthorized role for cash recording'],
                    companyId: $user->company_id,
                    userId: $user->id
                );
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only authorized Cashiers and Admins can record cash payments.',
                ], 403);
            }

            if (\Spatie\Permission\Models\Role::exists() && $user->roles()->count() > 0 && !$isAuthorized) {
                app(\App\Services\AuditService::class)->log(
                    action: 'auth.access_denied',
                    auditable: $user,
                    newValues: ['endpoint' => '/api/payment/cash', 'reason' => 'Unauthorized role for cash recording'],
                    companyId: $user->company_id,
                    userId: $user->id
                );
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only authorized Cashiers and Admins can record cash payments.',
                ], 403);
            }
        }

        $companyId = $user?->company_id ?? $request->input('company_id');
        $cashierId = auth()->id();

        $result = $this->paymentService->recordCashPayment(
            $request->all(),
            $cashierId,
            $companyId
        );

        return response()->json([
            'success'             => true,
            'message'             => 'Cash payment recorded successfully',
            'payment'             => $result['payment'],
            'invoice_number'      => $result['invoice']->invoice_number,
            'outstanding_balance' => $result['outstanding_balance'],
            'payment_status'      => $result['payment_status'],
        ], 200);
    }

    public function verifyOnline(Request $request)
    {
        $request->validate([
            'reference' => 'required|string',
        ]);

        $companyId = auth()->user()?->company_id ?? $request->input('company_id');
        $result = $this->paymentService->verifyAndRecordPaystackPayment($request->input('reference'), $companyId);

        return response()->json([
            'success'             => true,
            'message'             => 'Paystack transaction verified successfully',
            'payment'             => $result['payment'],
            'payment_status'      => $result['payment_status'],
            'outstanding_balance' => $result['outstanding_balance'] ?? 0.00,
        ], 200);
    }

    public function paymentHistory(Request $request)
    {
        $companyId = auth()->user()?->company_id;

        $query = Payment::with(['invoice', 'order', 'buyer', 'cashier'])->latest('id');

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        $payments = $query->paginate(50);

        return response()->json([
            'success' => true,
            'data'    => $payments,
        ]);
    }

    public function payment(Request $request)
    {
        $request->validate([
            'amount'      => 'required|numeric|min:1',
            'stripeToken' => 'required|string',
            'email'       => 'nullable|email',
        ]);

        Stripe::setApiKey(env('STRIPE_SECRET'));

        $charge = Charge::create([
            'amount'      => $request->amount * 100,
            'currency'    => 'inr',
            'source'      => $request->stripeToken,
            'description' => 'Test Payment',
        ]);

        $payment = Payment::create([
            'payment_id'     => $charge->id,
            'customer_email' => $request->email ?? null,
            'amount'         => $request->amount,
            'currency'       => 'INR',
            'status'         => $charge->status,
            'response'       => json_encode($charge),
        ]);

        return response()->json([
            'message' => 'Payment successful',
            'data'    => $payment,
        ]);
    }
}
