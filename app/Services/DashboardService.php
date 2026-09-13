<?php

namespace App\Services;

use App\Models\BaleBatch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderRequest;
use App\Models\Payment;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function summary(): array
    {
        $today = now()->toDateString();

        $todaySales = (float) Invoice::whereIn('status', ['finalized', 'amended'])
            ->whereDate('created_at', $today)
            ->whereNull('original_invoice_id')
            ->sum('total_amount');

        $todayCollected = (float) Payment::where('status', 'verified')
            ->whereDate('created_at', $today)
            ->sum('amount');

        $outstanding = (float) Invoice::where('status', 'finalized')
            ->where('payment_status', '!=', 'paid')
            ->sum(DB::raw('total_amount - paid_amount'));

        $soldToday = (int) StockMovement::where('movement_type', 'sale')
            ->whereDate('movement_date', $today)
            ->sum('quantity');

        $releasedToday = (int) StockMovement::where('movement_type', 'release')
            ->whereDate('movement_date', $today)
            ->sum('quantity');

        $stock = BaleBatch::query()
            ->selectRaw('
                COALESCE(SUM(qty_available), 0) as available,
                COALESCE(SUM(qty_sold), 0) as sold,
                COALESCE(SUM(qty_released), 0) as released,
                COALESCE(SUM(qty_damaged), 0) as damaged
            ')
            ->first();

        $stockByCategory = BaleBatch::query()
            ->join('item_category', 'item_category.id', '=', 'tbl_bale_batches.category_id')
            ->selectRaw('item_category.category_name, SUM(tbl_bale_batches.qty_available) as available')
            ->groupBy('item_category.category_name')
            ->orderByDesc(DB::raw('SUM(tbl_bale_batches.qty_available)'))
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category_name,
                'available' => (int) $row->available,
            ])
            ->all();

        $last7Days = collect(range(6, 0))
            ->map(function ($daysAgo) {
                $date = now()->subDays($daysAgo)->toDateString();

                return [
                    'date' => $date,
                    'label' => now()->subDays($daysAgo)->format('D'),
                    'sales' => (float) Invoice::whereIn('status', ['finalized', 'amended'])
                        ->whereDate('created_at', $date)
                        ->whereNull('original_invoice_id')
                        ->sum('total_amount'),
                ];
            })
            ->all();

        $recentOrders = Order::with('customer')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($order) => [
                'uuid' => $order->uuid,
                'order_no' => $order->order_no,
                'customer' => $order->customer?->name ?? $order->customer_name,
                'invoice_number' => $order->invoice_code,
                'amount' => (float) $order->total_amount,
                'payment_status' => $order->payment_status,
                'pickup_status' => $order->pickup_status,
                'created_at' => $order->created_at?->toDateTimeString(),
            ])
            ->all();

        return [
            'kpis' => [
                'today_sales' => $todaySales,
                'today_collected' => $todayCollected,
                'outstanding_receivables' => max($outstanding, 0),
                'pending_requests' => OrderRequest::pending()->count(),
                'customers' => Customer::count(),
                'sold_today' => $soldToday,
                'released_today' => $releasedToday,
            ],
            'stock' => [
                'available' => (int) ($stock->available ?? 0),
                'sold' => (int) ($stock->sold ?? 0),
                'released' => (int) ($stock->released ?? 0),
                'damaged' => (int) ($stock->damaged ?? 0),
                'by_category' => $stockByCategory,
            ],
            'last_7_days' => $last7Days,
            'recent_orders' => $recentOrders,
            'generated_at' => now()->toDateTimeString(),
        ];
    }
}
