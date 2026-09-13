<?php

namespace App\Services;

use App\Jobs\SendWhatsAppText;
use App\Models\BaleBatch;
use App\Models\Customer;
use App\Models\Item_category;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class StockNotificationService
{
    /**
     * Announce newly registered stock to customers. Called once per
     * registration event with every line, so each customer receives a single
     * combined message, queued per recipient.
     *
     * Lines: [['category_id' => int, 'quantity' => int], ...]
     */
    public function broadcastArrival(array $lines): int
    {
        $categoryNames = Item_category::whereIn(
            'id',
            array_column($lines, 'category_id')
        )->pluck('category_name', 'id');

        $lineTexts = collect($lines)
            ->map(function ($line) use ($categoryNames) {
                $name = $categoryNames[$line['category_id']] ?? null;

                return $name ? "• {$line['quantity']} × {$name}" : null;
            })
            ->filter()
            ->all();

        if (empty($lineTexts)) {
            return 0;
        }

        $message = "🚛 New stock has arrived at Orvell!\n\n"
            . implode("\n", $lineTexts) . "\n\n"
            . 'Reply here to check prices or place your order before it runs out 📦';

        return $this->sendToCustomers($message, 'stock_arrival');
    }

    /**
     * Admin-triggered availability update for one batch ("stock remaining").
     */
    public function broadcastAvailability(BaleBatch $batch): int
    {
        $batch->loadMissing('category');

        if ($batch->qty_available <= 0) {
            return 0;
        }

        $category = $batch->category?->category_name ?? 'bales';

        $message = "📦 Stock update from Orvell:\n\n"
            . "{$batch->qty_available} bale" . ($batch->qty_available === 1 ? '' : 's')
            . " of {$category} available right now.\n\n"
            . 'Reply here to place your order before they are gone!';

        return $this->sendToCustomers($message, "stock_update:batch_{$batch->id}");
    }

    /**
     * Queue one message per notifiable customer (onboarded, with a WhatsApp
     * identity). Returns how many were queued.
     */
    private function sendToCustomers(string $message, string $context): int
    {
        $recipients = $this->recipients();

        foreach ($recipients as $customer) {
            $chatId = $customer->wa_id ?? $customer->whatsapp_number;
            SendWhatsAppText::dispatch($chatId, $message, $context);
        }

        Log::info('Stock notification queued', [
            'context' => $context,
            'recipients' => $recipients->count(),
        ]);

        return $recipients->count();
    }

    private function recipients(): Collection
    {
        return Customer::query()
            ->where('onboarding_status', 1)
            ->where(function ($q) {
                $q->whereNotNull('wa_id')->orWhereNotNull('whatsapp_number');
            })
            ->get();
    }
}
