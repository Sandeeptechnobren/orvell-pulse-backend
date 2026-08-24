<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Bale;
use App\Models\Container;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\BankDeposit;
use App\Models\Expense;
use App\Models\Item_category;
use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Tag(
 *     name="Dashboard",
 *     description="Real-time Operational Dashboard & KPI Analytics APIs"
 * )
 */
class DashboardController extends Controller
{
    /**
     * Get Real-time Dashboard Overview
     *
     * @OA\Get(
     *     path="/api/dashboard/overview",
     *     tags={"Dashboard"},
     *     summary="Retrieve live operational KPIs, cash in till, inventory breakdown, and alerts for company",
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Live dashboard overview retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="company", type="object"),
     *                 @OA\Property(property="financials", type="object"),
     *                 @OA\Property(property="inventory", type="object"),
     *                 @OA\Property(property="orders", type="object"),
     *                 @OA\Property(property="low_stock_alerts", type="array", @OA\Items(type="object")),
     *                 @OA\Property(property="recent_activity", type="array", @OA\Items(type="object"))
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function overview(Request $request): JsonResponse
    {
        $user = auth()->user();
        $companyId = $user?->company_id ?? $request->input('company_id');

        if (!$companyId) {
            return response()->json([
                'success' => false,
                'message' => 'Company context required.',
            ], 422);
        }

        $company = Company::find($companyId);
        $today = now()->toDateString();
        $currency = $company?->currency ?? 'GHS';

        // 1. Financial KPIs (Today)
        $todaySales = (float) Invoice::where('company_id', $companyId)
            ->where('status', 'finalized')
            ->whereDate('created_at', $today)
            ->sum('total_amount');

        $todayCashCollected = (float) Payment::where('company_id', $companyId)
            ->where('payment_method', 'cash')
            ->where('status', 'verified')
            ->whereDate('created_at', $today)
            ->sum('amount');

        $todayOnlineCollected = (float) Payment::where('company_id', $companyId)
            ->where('payment_method', 'online')
            ->where('status', 'verified')
            ->whereDate('created_at', $today)
            ->sum('amount');

        $todayBankDeposits = (float) BankDeposit::where('company_id', $companyId)
            ->whereDate('deposit_date', $today)
            ->sum('amount');

        $todayExpenses = (float) Expense::where('company_id', $companyId)
            ->whereDate('expense_date', $today)
            ->sum('amount');

        $netCashInTill = round($todayCashCollected - $todayBankDeposits - $todayExpenses, 2);

        // 2. Physical Inventory KPIs
        $baleCounts = Bale::where('company_id', $companyId)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $totalBales = array_sum($baleCounts);
        $availableBales = $baleCounts['available'] ?? 0;
        $reservedBales = $baleCounts['reserved'] ?? 0;
        $releasedBales = $baleCounts['released'] ?? 0;
        $damagedBales = $baleCounts['damaged'] ?? 0;

        $activeContainers = Container::where('company_id', $companyId)
            ->where('status', 'in_stock')
            ->count();

        // 3. Orders & Pickup KPIs
        $todayOrdersCount = Order::where('company_id', $companyId)
            ->whereDate('created_at', $today)
            ->count();

        $pendingPickupsCount = Order::where('company_id', $companyId)
            ->where('pickup_status', 'pending')
            ->count();

        $completedPickupsToday = Order::where('company_id', $companyId)
            ->where('pickup_status', 'completed')
            ->whereDate('updated_at', $today)
            ->count();

        // 4. Low Stock Alerts (Categories with <= 3 available physical bales)
        $lowStockCategories = Item_category::all()->map(function ($cat) use ($companyId) {
            $available = Bale::where('company_id', $companyId)
                ->where('item_category_id', $cat->id)
                ->where('status', 'available')
                ->count();
            return [
                'category_id'     => $cat->id,
                'category_name'   => $cat->category_name,
                'available_bales' => $available,
                'is_low_stock'    => $available <= 3,
            ];
        })->filter(fn ($c) => $c['is_low_stock'])->values()->toArray();

        // 5. Recent Activity (Recent Audit Logs)
        $recentActivity = AuditLog::where('company_id', $companyId)
            ->with('user:id,name,email')
            ->latest('id')
            ->take(10)
            ->get()
            ->map(function ($log) {
                return [
                    'id'         => $log->id,
                    'action'     => $log->action,
                    'user_name'  => $log->user?->name ?? 'System',
                    'created_at' => $log->created_at?->toDateTimeString(),
                    'details'    => $log->new_values,
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => [
                'company' => [
                    'id'       => $company->id ?? $companyId,
                    'name'     => $company->company_name ?? 'ORVELL PULSE',
                    'currency' => $currency,
                ],
                'financials' => [
                    'currency'               => $currency,
                    'today_sales'            => $todaySales,
                    'today_cash_collected'   => $todayCashCollected,
                    'today_online_collected' => $todayOnlineCollected,
                    'today_bank_deposits'    => $todayBankDeposits,
                    'today_expenses'         => $todayExpenses,
                    'net_cash_in_till'       => $netCashInTill,
                ],
                'inventory' => [
                    'total_bales'       => $totalBales,
                    'available_bales'   => $availableBales,
                    'reserved_bales'    => $reservedBales,
                    'released_bales'    => $releasedBales,
                    'damaged_bales'     => $damagedBales,
                    'active_containers' => $activeContainers,
                ],
                'orders' => [
                    'today_orders_count'      => $todayOrdersCount,
                    'pending_pickups_count'   => $pendingPickupsCount,
                    'completed_pickups_today' => $completedPickupsToday,
                ],
                'low_stock_alerts' => $lowStockCategories,
                'recent_activity'  => $recentActivity,
            ],
        ], 200);
    }
}
