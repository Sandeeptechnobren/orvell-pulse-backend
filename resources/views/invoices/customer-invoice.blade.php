<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Invoice {{ $invoice->invoice_number }}</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; padding: 32px; }
    .header { width: 100%; margin-bottom: 28px; }
    .header td { vertical-align: top; }
    .brand { font-size: 26px; font-weight: bold; color: #166534; letter-spacing: 1px; }
    .brand-sub { font-size: 10px; color: #6b7280; margin-top: 3px; }
    .doc-title { text-align: right; }
    .doc-title h1 { font-size: 22px; color: #111827; letter-spacing: 2px; }
    .doc-title .inv-no { font-size: 13px; color: #374151; margin-top: 4px; }
    .badge { display: inline-block; margin-top: 8px; padding: 4px 14px; border-radius: 12px; font-size: 11px; font-weight: bold; }
    .badge-paid { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
    .badge-unpaid { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
    .meta { width: 100%; margin-bottom: 24px; }
    .meta td { vertical-align: top; width: 50%; }
    .meta h3 { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; margin-bottom: 6px; }
    .meta p { line-height: 1.6; }
    .meta .name { font-weight: bold; font-size: 13px; }
    .lines { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
    .lines th { background: #166534; color: #ffffff; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; padding: 8px 10px; text-align: left; }
    .lines th.num, .lines td.num { text-align: right; }
    .lines td { padding: 8px 10px; border-bottom: 1px solid #e5e7eb; }
    .lines tr.alt td { background: #f9fafb; }
    .totals { width: 260px; margin-left: auto; border-collapse: collapse; }
    .totals td { padding: 5px 10px; }
    .totals td.num { text-align: right; }
    .totals tr.grand td { border-top: 2px solid #166534; font-weight: bold; font-size: 14px; padding-top: 8px; }
    .totals tr.paid td { color: #166534; }
    .totals tr.due td { color: #92400e; font-weight: bold; }
    .pickup { margin-top: 26px; border: 2px dashed #166534; border-radius: 8px; padding: 14px 18px; background: #f0fdf4; }
    .pickup .label { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #166534; }
    .pickup .code { font-size: 20px; font-weight: bold; color: #166534; letter-spacing: 3px; margin-top: 4px; }
    .pickup .hint { font-size: 10px; color: #4b5563; margin-top: 6px; }
    .footer { margin-top: 36px; padding-top: 14px; border-top: 1px solid #e5e7eb; font-size: 10px; color: #9ca3af; text-align: center; line-height: 1.6; }
</style>
</head>
<body>

<table class="header">
    <tr>
        <td>
            <div class="brand">ORVELL</div>
            <div class="brand-sub">Wholesale Bale Importer &middot; Powered by PULSE</div>
        </td>
        <td class="doc-title">
            <h1>INVOICE</h1>
            <div class="inv-no">{{ $invoice->invoice_number }}</div>
            @if ($invoice->payment_status === 'paid')
                <span class="badge badge-paid">PAID</span>
            @elseif ($invoice->payment_status === 'partial')
                <span class="badge badge-unpaid">PARTIALLY PAID</span>
            @else
                <span class="badge badge-unpaid">UNPAID</span>
            @endif
        </td>
    </tr>
</table>

<table class="meta">
    <tr>
        <td>
            <h3>Billed to</h3>
            <p class="name">{{ $invoice->customer?->name ?? $order?->customer_name ?? 'Customer' }}</p>
            @if ($invoice->customer?->buyer_id)
                <p>Buyer ID: {{ $invoice->customer->buyer_id }}</p>
            @endif
            @if ($invoice->customer?->whatsapp_number)
                <p>WhatsApp: +{{ $invoice->customer->whatsapp_number }}</p>
            @endif
            @if ($invoice->customer?->city)
                <p>{{ $invoice->customer->city }}{{ $invoice->customer->country ? ', ' . $invoice->customer->country : '' }}</p>
            @endif
        </td>
        <td style="text-align: right;">
            <h3>Details</h3>
            <p>Invoice date: {{ $invoice->issued_date?->format('d M Y') }}</p>
            @if ($order?->order_no)
                <p>Order: {{ $order->order_no }}</p>
            @endif
            <p>Currency: GHS (Ghana Cedi)</p>
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th style="width: 46%;">Description</th>
            <th class="num" style="width: 14%;">Qty</th>
            <th class="num" style="width: 20%;">Unit price</th>
            <th class="num" style="width: 20%;">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $index => $line)
            <tr @if ($index % 2 === 1) class="alt" @endif>
                <td>{{ $line['description'] }}</td>
                <td class="num">{{ $line['quantity'] }}</td>
                <td class="num">{{ number_format($line['unit_price'], 2) }}</td>
                <td class="num">{{ number_format($line['total'], 2) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr>
        <td>Subtotal</td>
        <td class="num">GHS {{ number_format((float) $invoice->subtotal, 2) }}</td>
    </tr>
    @if ((float) $invoice->tax_amount > 0)
        <tr>
            <td>Tax</td>
            <td class="num">GHS {{ number_format((float) $invoice->tax_amount, 2) }}</td>
        </tr>
    @endif
    @if ((float) $invoice->discount_amount > 0)
        <tr>
            <td>Discount</td>
            <td class="num">- GHS {{ number_format((float) $invoice->discount_amount, 2) }}</td>
        </tr>
    @endif
    <tr class="grand">
        <td>Total</td>
        <td class="num">GHS {{ number_format((float) $invoice->total_amount, 2) }}</td>
    </tr>
    <tr class="paid">
        <td>Paid</td>
        <td class="num">GHS {{ number_format((float) $invoice->paid_amount, 2) }}</td>
    </tr>
    @if ((float) $invoice->total_amount - (float) $invoice->paid_amount > 0.009)
        <tr class="due">
            <td>Balance due</td>
            <td class="num">GHS {{ number_format((float) $invoice->total_amount - (float) $invoice->paid_amount, 2) }}</td>
        </tr>
    @endif
</table>

@if ($invoice->payment_status === 'paid' && $order?->pickup_code)
    <div class="pickup">
        <div class="label">Pickup code</div>
        <div class="code">{{ $order->pickup_code }}</div>
        <div class="hint">Show this code at the Orvell warehouse to collect your goods. It never expires.</div>
    </div>
@endif

<div class="footer">
    Thank you for shopping with Orvell.<br>
    This invoice was generated automatically by Orvell PULSE on {{ now()->format('d M Y, H:i') }}.
</div>

</body>
</html>
