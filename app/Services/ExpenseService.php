<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseService
{
    protected AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * Record a operational warehouse expense with server-side monetary validation and company isolation.
     *
     * @param array $data
     * @param int $staffId
     * @param int|null $companyId
     * @return Expense
     * @throws ValidationException
     */
    public function recordExpense(array $data, int $staffId, ?int $companyId = null): Expense
    {
        return DB::transaction(function () use ($data, $staffId, $companyId) {
            $amount = round((float) ($data['amount'] ?? 0.00), 2);

            if ($amount <= 0.00) {
                throw ValidationException::withMessages([
                    'amount' => ['Expense amount must be greater than zero.'],
                ]);
            }

            $targetCompanyId = $companyId ?? $data['company_id'] ?? null;

            if (!$targetCompanyId) {
                throw ValidationException::withMessages([
                    'company_id' => ['Company ID is required to record an expense.'],
                ]);
            }

            $company = Company::find($targetCompanyId);
            if (!$company) {
                throw ValidationException::withMessages([
                    'company_id' => ['Company not found.'],
                ]);
            }

            $category = $data['category'] ?? 'miscellaneous';
            $currency = $data['currency'] ?? $company->currency ?? 'GHS';
            $description = $data['description'] ?? 'Warehouse expense';
            $expenseDate = $data['expense_date'] ?? now()->toDateString();
            $receiptImage = $data['receipt_image'] ?? null;
            $clientId = $data['client_id'] ?? null;

            $expense = Expense::create([
                'company_id'    => $targetCompanyId,
                'client_id'     => $clientId,
                'staff_id'      => $staffId,
                'category'      => $category,
                'amount'        => $amount,
                'currency'      => $currency,
                'description'   => $description,
                'receipt_image' => $receiptImage,
                'expense_date'  => $expenseDate,
                'is_verified'   => $data['is_verified'] ?? true,
            ]);

            $this->auditService->log(
                action: 'expense.recorded',
                auditable: $expense,
                newValues: [
                    'expense_number' => $expense->expense_number,
                    'category'       => $category,
                    'amount'         => $amount,
                    'currency'       => $currency,
                    'staff_id'       => $staffId,
                ],
                companyId: $targetCompanyId,
                userId: $staffId
            );

            return $expense->load(['company', 'staff']);
        });
    }

    /**
     * List expenses with company isolation and filtering.
     *
     * @param array $filters
     * @param int|null $companyId
     * @return mixed
     */
    public function listExpenses(array $filters = [], ?int $companyId = null)
    {
        $query = Expense::with(['company', 'staff'])->latest('expense_date');

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (!empty($filters['start_date'])) {
            $query->where('expense_date', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('expense_date', '<=', $filters['end_date']);
        }

        return $query->paginate(50);
    }

    /**
     * Get aggregate breakdown of expenses by category.
     *
     * @param string|null $startDate
     * @param string|null $endDate
     * @param int|null $companyId
     * @return array
     */
    public function getExpenseSummary(?string $startDate = null, ?string $endDate = null, ?int $companyId = null): array
    {
        $query = Expense::query();

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        if ($startDate) {
            $query->where('expense_date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('expense_date', '<=', $endDate);
        }

        $byCategory = (clone $query)->select('category', DB::raw('SUM(amount) as total_amount'), DB::raw('COUNT(*) as count'))
            ->groupBy('category')
            ->get()
            ->keyBy('category')
            ->toArray();

        $grandTotal = (float) (clone $query)->sum('amount');

        return [
            'total_expense_amount' => $grandTotal,
            'categories'           => $byCategory,
        ];
    }
}
