<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoicePdfService
{
    /**
     * Render the customer invoice as raw PDF bytes.
     */
    public function generate(Invoice $invoice): string
    {
        $invoice->loadMissing(['customer', 'order', 'items']);

        // Group FIFO batch splits so the customer sees one line per category.
        $lines = collect($invoice->items)
            ->groupBy(fn ($item) => $item->item_category_id ?? $item->item_description)
            ->map(function ($group) {
                $first = $group->first();
                $quantity = (int) $group->sum('quantity');
                $unitPrice = (float) $first->unit_price;

                return [
                    'description' => $first->item_description,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total' => (float) $group->sum('total_price'),
                ];
            })
            ->values()
            ->all();

        return Pdf::loadView('invoices.customer-invoice', [
            'invoice' => $invoice,
            'order' => $invoice->order,
            'lines' => $lines,
        ])
            ->setPaper('a4')
            ->output();
    }

    public function filename(Invoice $invoice): string
    {
        return "Orvell-{$invoice->invoice_number}.pdf";
    }
}
