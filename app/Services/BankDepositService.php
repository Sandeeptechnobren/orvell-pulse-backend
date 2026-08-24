<?php

namespace App\Services;

use App\Models\BankDeposit;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankDepositService
{
    protected AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * Record a bank deposit with amount validation, duplicate reference check, and company isolation.
     *
     * @param array $data
     * @param int $cashierId
     * @param int|null $companyId
     * @return BankDeposit
     * @throws ValidationException
     */
    public function recordDeposit(array $data, int $cashierId, ?int $companyId = null): BankDeposit
    {
        return DB::transaction(function () use ($data, $cashierId, $companyId) {
            $amount = round((float) ($data['amount'] ?? 0.00), 2);

            if ($amount <= 0.00) {
                throw ValidationException::withMessages([
                    'amount' => ['Deposit amount must be greater than zero.'],
                ]);
            }

            $targetCompanyId = $companyId ?? $data['company_id'] ?? null;

            if (!$targetCompanyId) {
                throw ValidationException::withMessages([
                    'company_id' => ['Company ID is required to record a bank deposit.'],
                ]);
            }

            $company = Company::find($targetCompanyId);
            if (!$company) {
                throw ValidationException::withMessages([
                    'company_id' => ['Company not found.'],
                ]);
            }

            $referenceNumber = trim($data['reference_number'] ?? '');
            if (!empty($referenceNumber)) {
                $duplicate = BankDeposit::where('company_id', $targetCompanyId)
                    ->where('reference_number', $referenceNumber)
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'reference_number' => ["Bank deposit with reference '{$referenceNumber}' already exists for this company."],
                    ]);
                }
            }

            $bankName = $data['bank_name'] ?? 'Commercial Bank';
            $accountNumber = $data['account_number'] ?? null;
            $depositDate = $data['deposit_date'] ?? now()->toDateString();
            $currency = $data['currency'] ?? $company->currency ?? 'GHS';
            $notes = $data['notes'] ?? 'End of day bank cash deposit';
            $depositSlipImage = $data['deposit_slip_image'] ?? null;
            $clientId = $data['client_id'] ?? null;

            $deposit = BankDeposit::create([
                'company_id'         => $targetCompanyId,
                'client_id'          => $clientId,
                'cashier_id'         => $cashierId,
                'bank_name'          => $bankName,
                'account_number'     => $accountNumber,
                'amount'             => $amount,
                'currency'           => $currency,
                'reference_number'   => !empty($referenceNumber) ? $referenceNumber : null,
                'deposit_slip_image' => $depositSlipImage,
                'deposit_date'       => $depositDate,
                'notes'              => $notes,
            ]);

            $this->auditService->log(
                action: 'bank_deposit.recorded',
                auditable: $deposit,
                newValues: [
                    'deposit_number'   => $deposit->deposit_number,
                    'bank_name'        => $bankName,
                    'account_number'   => $accountNumber,
                    'amount'           => $amount,
                    'reference_number' => $referenceNumber,
                ],
                companyId: $targetCompanyId,
                userId: $cashierId
            );

            return $deposit->load(['company', 'cashier']);
        });
    }

    /**
     * List bank deposits with company isolation and filters.
     *
     * @param array $filters
     * @param int|null $companyId
     * @return mixed
     */
    public function listDeposits(array $filters = [], ?int $companyId = null)
    {
        $query = BankDeposit::with(['company', 'cashier'])->latest('deposit_date');

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        if (!empty($filters['start_date'])) {
            $query->where('deposit_date', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->where('deposit_date', '<=', $filters['end_date']);
        }

        if (!empty($filters['bank_name'])) {
            $query->where('bank_name', 'like', '%' . $filters['bank_name'] . '%');
        }

        return $query->paginate(50);
    }

    /**
     * Get aggregate summary of bank deposits for a period.
     *
     * @param string|null $startDate
     * @param string|null $endDate
     * @param int|null $companyId
     * @return array
     */
    public function getDepositSummary(?string $startDate = null, ?string $endDate = null, ?int $companyId = null): array
    {
        $query = BankDeposit::query();

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        if ($startDate) {
            $query->where('deposit_date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('deposit_date', '<=', $endDate);
        }

        $totalAmount = (float) (clone $query)->sum('amount');
        $count = (clone $query)->count();

        return [
            'total_deposit_amount' => $totalAmount,
            'deposits_count'       => $count,
        ];
    }
}
