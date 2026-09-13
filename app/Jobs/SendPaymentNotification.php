<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\InvoicePdfService;
use App\Services\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendPaymentNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct(
        public int $paymentId
    ) {
    }

    public function handle(
        WhatsAppService $whatsAppService,
        InvoicePdfService $invoicePdfService
    ): void {
        $payment = Payment::with(['invoice.customer', 'invoice.order'])
            ->find($this->paymentId);

        if (!$payment || !$payment->invoice) {
            Log::warning('Payment notification skipped - payment or invoice missing', [
                'payment_id' => $this->paymentId,
            ]);
            return;
        }

        // Duplicate-send guard: a retried job never messages the customer twice.
        if ($payment->notified_at) {
            return;
        }

        $invoice = $payment->invoice;
        $customer = $invoice->customer;
        $chatId = $customer?->wa_id ?? $customer?->whatsapp_number;

        if (!$chatId) {
            $payment->forceFill(['notified_at' => now()])->save();
            return;
        }

        $amount = number_format((float) $payment->amount, 2);
        $isPaid = $invoice->payment_status === 'paid';

        if ($isPaid) {
            $pickupCode = $invoice->order?->pickup_code;
            $message = "Payment received - GHS {$amount} for invoice {$invoice->invoice_number} ✅\n\n"
                . "Your invoice is fully paid.\n"
                . ($pickupCode
                    ? "🎫 Pickup code: {$pickupCode}\n\nShow this code at the warehouse to collect your goods. It never expires."
                    : 'The team will share your pickup details shortly.');
        } else {
            $outstanding = number_format(
                max((float) $invoice->total_amount - (float) $invoice->paid_amount, 0),
                2
            );
            $message = "Payment received - GHS {$amount} for invoice {$invoice->invoice_number} ✅\n\n"
                . "Outstanding balance: GHS {$outstanding}.\n"
                . 'You will receive your pickup code once the invoice is fully paid.';
        }

        $whatsAppService->sendText($chatId, $message);

        if ($isPaid) {
            try {
                $pdf = $invoicePdfService->generate($invoice);
                $whatsAppService->sendDocument(
                    $chatId,
                    $pdf,
                    $invoicePdfService->filename($invoice),
                    "Invoice {$invoice->invoice_number} - Orvell"
                );
            } catch (Throwable $e) {
                // The receipt text already went out - do not retry the whole
                // job (that would duplicate the text). Log and move on.
                Log::error('Invoice PDF send failed', [
                    'invoice' => $invoice->invoice_number,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $payment->forceFill(['notified_at' => now()])->save();
    }

    public function failed(Throwable $e): void
    {
        Log::error('Payment notification job failed permanently', [
            'payment_id' => $this->paymentId,
            'error' => $e->getMessage(),
        ]);
    }
}
