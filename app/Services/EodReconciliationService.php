<?php

namespace App\Services;

use App\Models\DailyReconciliation;
use App\Models\DailySnapshot;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\BankDeposit;
use App\Models\Expense;
use App\Models\Bale;
use App\Models\InventoryTransaction;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EodReconciliationService
{
    protected AuditService $auditService;
    protected ReconciliationService $inventoryReconService;

    public function __construct(AuditService $auditService, ReconciliationService $inventoryReconService)
    {
        $this->auditService = $auditService;
        $this->inventoryReconService = $inventoryReconService;
    }

    /**
     * Generate End-of-Day (EOD) financial and physical inventory reconciliation for a specified date and company.
     * Computes exact database aggregations, detects variances, and stores immutable snapshot records.
     *
     * @param string $date (YYYY-MM-DD)
     * @param int $reconcilerId
     * @param int|null $companyId
     * @param bool $save
     * @return array
     * @throws ValidationException
     */
    public function generateDailyReconciliation(string $date, int $reconcilerId, ?int $companyId = null, bool $save = true): array
    {
        return DB::transaction(function () use ($date, $reconcilerId, $companyId, $save) {
            if (!$companyId) {
                throw ValidationException::withMessages([
                    'company_id' => ['Company ID is required for EOD reconciliation.'],
                ]);
            }

            $company = Company::find($companyId);
            if (!$company) {
                throw ValidationException::withMessages([
                    'company_id' => ['Company not found.'],
                ]);
            }

            // 1. Financial: Total Sales (Finalized Invoices created on this date)
            $totalSales = (float) Invoice::where('company_id', $companyId)
                ->whereDate('created_at', $date)
                ->whereIn('status', ['finalized', 'amended'])
                ->sum('total_amount');

            // 2. Financial: Total Cash Collected on this date
            $totalCashCollected = (float) Payment::where('company_id', $companyId)
                ->whereDate('created_at', $date)
                ->where('payment_method', 'cash')
                ->where('status', 'verified')
                ->sum('amount');

            // 3. Financial: Total Bank Deposits on this date
            $totalBankDeposits = (float) BankDeposit::where('company_id', $companyId)
                ->whereDate('deposit_date', $date)
                ->sum('amount');

            // 4. Financial: Total Operational Expenses on this date
            $totalExpenses = (float) Expense::where('company_id', $companyId)
                ->whereDate('expense_date', $date)
                ->sum('amount');

            // 5. Financial: Pending / Unsettled Invoices (as of this date)
            $pendingInvoicesQuery = Invoice::where('company_id', $companyId)
                ->whereDate('created_at', '<=', $date)
                ->where('status', 'finalized')
                ->where('payment_status', '!=', 'paid');

            $pendingInvoicesCount = $pendingInvoicesQuery->count();
            $pendingInvoicesAmount = (float) $pendingInvoicesQuery->selectRaw('SUM(total_amount - paid_amount) as outstanding')->value('outstanding');

            // 6. Net Cash in hand position
            $netCashDifference = round($totalCashCollected - $totalBankDeposits - $totalExpenses, 2);

            // 7. Physical Stock Movements on this date
            $unitsAdded = (int) InventoryTransaction::where('company_id', $companyId)
                ->whereDate('created_at', $date)
                ->where('transaction_type', 'container_arrival')
                ->count();

            $unitsSold = (int) InventoryTransaction::where('company_id', $companyId)
                ->whereDate('created_at', $date)
                ->where('transaction_type', 'sale_reservation')
                ->count();

            $unitsReleased = (int) InventoryTransaction::where('company_id', $companyId)
                ->whereDate('created_at', $date)
                ->where('transaction_type', 'pickup_release')
                ->count();

            $unitsAdjusted = (int) InventoryTransaction::where('company_id', $companyId)
                ->whereDate('created_at', $date)
                ->whereIn('transaction_type', ['adjustment_deduction', 'adjustment_addition'])
                ->count();

            // 8. Closing stock position
            $activeBalesQuery = Bale::where('company_id', $companyId)
                ->whereIn('status', ['available', 'reserved']);

            $closingStockUnits = $activeBalesQuery->count();
            $closingStockValue = (float) $activeBalesQuery->sum('cost_price');

            // 9. Category breakdown
            $categoryBreakdown = Bale::where('company_id', $companyId)
                ->whereIn('status', ['available', 'reserved'])
                ->select('item_category_id', DB::raw('count(*) as count'), DB::raw('SUM(cost_price) as value'))
                ->groupBy('item_category_id')
                ->get()
                ->toArray();

            // 10. Run Stock Integrity Check
            $stockIntegrity = $this->inventoryReconService->verifyStockIntegrity($companyId);
            $stockVarianceUnits = $stockIntegrity['discrepancy_count'] ?? 0;

            $discrepancies = [
                'cash_position' => [
                    'cash_collected'   => $totalCashCollected,
                    'bank_deposits'    => $totalBankDeposits,
                    'expenses'         => $totalExpenses,
                    'net_cash_balance' => $netCashDifference,
                ],
                'stock_integrity' => $stockIntegrity,
            ];

            $dailyReconciliation = null;
            $dailySnapshot = null;

            if ($save) {
                // Upsert DailyReconciliation
                $dailyReconciliation = DailyReconciliation::updateOrCreate(
                    [
                        'company_id'          => $companyId,
                        'reconciliation_date' => $date,
                    ],
                    [
                        'total_sales_amount'      => $totalSales,
                        'total_cash_collected'    => $totalCashCollected,
                        'total_bank_deposits'     => $totalBankDeposits,
                        'total_expenses'          => $totalExpenses,
                        'pending_invoices_count'  => $pendingInvoicesCount,
                        'pending_invoices_amount' => $pendingInvoicesAmount,
                        'stock_variance_units'    => $stockVarianceUnits,
                        'status'                  => 'reconciled',
                        'discrepancies'           => $discrepancies,
                        'reconciled_by'           => $reconcilerId,
                    ]
                );

                // Upsert DailySnapshot
                $dailySnapshot = DailySnapshot::updateOrCreate(
                    [
                        'company_id'    => $companyId,
                        'snapshot_date' => $date,
                    ],
                    [
                        'opening_stock_units' => $closingStockUnits - $unitsAdded + $unitsReleased,
                        'opening_stock_value' => $closingStockValue,
                        'units_added'         => $unitsAdded,
                        'units_sold'          => $unitsSold,
                        'units_released'      => $unitsReleased,
                        'units_adjusted'      => $unitsAdjusted,
                        'closing_stock_units' => $closingStockUnits,
                        'closing_stock_value' => $closingStockValue,
                        'category_breakdown'  => $categoryBreakdown,
                    ]
                );

                $this->auditService->log(
                    action: 'eod.reconciliation_generated',
                    auditable: $dailyReconciliation,
                    newValues: [
                        'reconciliation_date'  => $date,
                        'total_sales'          => $totalSales,
                        'total_cash_collected' => $totalCashCollected,
                        'total_bank_deposits'  => $totalBankDeposits,
                        'total_expenses'       => $totalExpenses,
                        'stock_variance_units' => $stockVarianceUnits,
                    ],
                    companyId: $companyId,
                    userId: $reconcilerId
                );
            }

            return [
                'company_id'              => $companyId,
                'date'                    => $date,
                'total_sales_amount'      => $totalSales,
                'total_cash_collected'    => $totalCashCollected,
                'total_bank_deposits'     => $totalBankDeposits,
                'total_expenses'          => $totalExpenses,
                'pending_invoices_count'  => $pendingInvoicesCount,
                'pending_invoices_amount' => $pendingInvoicesAmount,
                'net_cash_balance'        => $netCashDifference,
                'stock_movement'          => [
                    'units_added'         => $unitsAdded,
                    'units_sold'          => $unitsSold,
                    'units_released'      => $unitsReleased,
                    'units_adjusted'      => $unitsAdjusted,
                    'closing_stock_units' => $closingStockUnits,
                    'closing_stock_value' => $closingStockValue,
                ],
                'stock_variance_units'    => $stockVarianceUnits,
                'reconciliation'          => $dailyReconciliation,
                'snapshot'                => $dailySnapshot,
            ];
        });
    }

    /**
     * Get existing reconciliation report for a specific date and company.
     */
    public function getReconciliationReport(string $date, ?int $companyId = null): ?DailyReconciliation
    {
        $query = DailyReconciliation::whereDate('reconciliation_date', $date)->with(['reconciler', 'company']);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        return $query->first();
    }
}
