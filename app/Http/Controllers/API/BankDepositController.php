<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\BankDepositService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BankDepositController extends Controller
{
    protected BankDepositService $depositService;

    public function __construct(BankDepositService $depositService)
    {
        $this->depositService = $depositService;
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'amount'             => 'required|numeric|min:0.01',
            'bank_name'          => 'required|string',
            'account_number'     => 'nullable|string',
            'reference_number'   => 'nullable|string',
            'deposit_date'       => 'nullable|date',
            'deposit_slip_image' => 'nullable|string',
            'notes'              => 'nullable|string',
        ]);

        $user = auth()->user();

        // RBAC: Verify user has Cashier, Admin, or Manager privileges
        if ($user instanceof User) {
            $isAuthorized = false;
            if (method_exists($user, 'hasRole')) {
                $isAuthorized = $user->hasRole(['Cashier', 'cashier', 'Admin', 'admin', 'Super Admin', 'super-admin', 'Manager', 'manager']);
            }
            if (!$isAuthorized && method_exists($user, 'hasRole') && $user->hasRole(['Salesperson', 'salesperson', 'Staff', 'staff'])) {
                app(\App\Services\AuditService::class)->log(
                    action: 'auth.access_denied',
                    auditable: $user,
                    newValues: ['endpoint' => '/api/bank-deposits/create', 'reason' => 'Unauthorized role for bank deposit'],
                    companyId: $user->company_id,
                    userId: $user->id
                );
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only authorized Cashiers, Managers, and Admins can record bank deposits.',
                ], 403);
            }
        }

        $companyId = $user?->company_id ?? $request->input('company_id');
        $cashierId = auth()->id();

        $deposit = $this->depositService->recordDeposit(
            $request->all(),
            $cashierId,
            $companyId
        );

        return response()->json([
            'success' => true,
            'message' => 'Bank deposit recorded successfully',
            'data'    => $deposit,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = auth()->user()?->company_id;
        $filters = $request->only(['bank_name', 'start_date', 'end_date']);

        $deposits = $this->depositService->listDeposits($filters, $companyId);

        return response()->json([
            'success' => true,
            'data'    => $deposits,
        ], 200);
    }

    public function summary(Request $request): JsonResponse
    {
        $companyId = auth()->user()?->company_id;
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $summary = $this->depositService->getDepositSummary($startDate, $endDate, $companyId);

        return response()->json([
            'success' => true,
            'data'    => $summary,
        ], 200);
    }
}
