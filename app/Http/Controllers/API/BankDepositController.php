<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\BankDepositService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *     name="Bank Deposits",
 *     description="Cashier Bank Deposit Management APIs"
 * )
 */
class BankDepositController extends Controller
{
    protected BankDepositService $depositService;

    public function __construct(BankDepositService $depositService)
    {
        $this->depositService = $depositService;
    }

    /**
     * Record Bank Deposit (Cashier / Admin)
     *
     * @OA\Post(
     *     path="/api/bank-deposits/create",
     *     tags={"Bank Deposits"},
     *     summary="Record cashier bank deposit of counter cash",
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"amount", "bank_name"},
     *             @OA\Property(property="amount", type="number", example=500.00),
     *             @OA\Property(property="bank_name", type="string", example="GCB Bank"),
     *             @OA\Property(property="account_number", type="string", example="1029384756"),
     *             @OA\Property(property="reference_number", type="string", example="DEP-2026-001"),
     *             @OA\Property(property="deposit_date", type="string", format="date", example="2026-08-09"),
     *             @OA\Property(property="deposit_slip_image", type="string", example="https://storage.orvell.com/slips/slip1.jpg"),
     *             @OA\Property(property="notes", type="string", example="Morning till cash deposit"),
     *             @OA\Property(property="company_id", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(response=201, description="Bank deposit recorded successfully"),
     *     @OA\Response(response=403, description="Unauthorized. Only authorized Cashiers, Managers, and Admins can record bank deposits"),
     *     @OA\Response(response=422, description="Validation error or duplicate reference")
     * )
     */
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

    /**
     * List Bank Deposits
     *
     * @OA\Get(
     *     path="/api/bank-deposits/list",
     *     tags={"Bank Deposits"},
     *     summary="List filtered bank deposits for authenticated company",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="bank_name",
     *         in="query",
     *         description="Filter by bank name",
     *         required=false,
     *         @OA\Schema(type="string", example="GCB Bank")
     *     ),
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         description="Filter start date (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date", example="2026-08-01")
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         description="Filter end date (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date", example="2026-08-31")
     *     ),
     *     @OA\Response(response=200, description="Bank deposits list retrieved successfully"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
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

    /**
     * Bank Deposits Period Summary
     *
     * @OA\Get(
     *     path="/api/bank-deposits/summary",
     *     tags={"Bank Deposits"},
     *     summary="Get bank deposit summary aggregated by bank for date range",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         description="Summary start date (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date", example="2026-08-01")
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         description="Summary end date (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date", example="2026-08-31")
     *     ),
     *     @OA\Response(response=200, description="Bank deposit summary retrieved successfully"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
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
