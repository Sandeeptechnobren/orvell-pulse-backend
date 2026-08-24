<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\EodReconciliationService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *     name="EOD Reconciliation",
 *     description="End-of-Day Financial and Inventory Reconciliation APIs"
 * )
 */
class EodReconciliationController extends Controller
{
    protected EodReconciliationService $eodService;

    public function __construct(EodReconciliationService $eodService)
    {
        $this->eodService = $eodService;
    }

    /**
     * Generate EOD Reconciliation Report (Admin / Manager Only)
     *
     * @OA\Post(
     *     path="/api/reconciliation/eod/generate",
     *     tags={"EOD Reconciliation"},
     *     summary="Generate End-of-Day financial and inventory reconciliation snapshot",
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"date"},
     *             @OA\Property(property="date", type="string", format="date", example="2026-08-09"),
     *             @OA\Property(property="company_id", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(response=200, description="EOD reconciliation generated successfully"),
     *     @OA\Response(response=403, description="Unauthorized. Only Admins and Managers can generate EOD reconciliation reports"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'date' => 'required|date_format:Y-m-d',
        ]);

        $user = auth()->user();

        // RBAC check
        if ($user instanceof User) {
            $isAuthorized = false;
            if (method_exists($user, 'hasRole')) {
                $isAuthorized = $user->hasRole(['Admin', 'admin', 'Super Admin', 'super-admin', 'Manager', 'manager']);
            }
            if (!$isAuthorized && method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['Salesperson', 'salesperson', 'Staff', 'staff', 'Cashier', 'cashier'])) {
                app(\App\Services\AuditService::class)->log(
                    action: 'auth.access_denied',
                    auditable: $user,
                    newValues: ['endpoint' => '/api/reconciliation/eod/generate', 'reason' => 'Unauthorized role for EOD generation'],
                    companyId: $user->company_id,
                    userId: $user->id
                );
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only Admins and Managers can generate EOD reconciliation reports.',
                ], 403);
            }
        }

        $companyId = $user?->company_id ?? $request->input('company_id');
        $reconcilerId = auth()->id();
        $date = $request->input('date');

        $result = $this->eodService->generateDailyReconciliation($date, $reconcilerId, $companyId, true);

        return response()->json([
            'success' => true,
            'message' => "EOD reconciliation generated successfully for {$date}",
            'data'    => $result,
        ], 200);
    }

    /**
     * Show existing EOD Reconciliation Report
     *
     * @OA\Get(
     *     path="/api/reconciliation/eod/show",
     *     tags={"EOD Reconciliation"},
     *     summary="Retrieve existing End-of-Day reconciliation report by date",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="date",
     *         in="query",
     *         description="Reconciliation report date (YYYY-MM-DD)",
     *         required=true,
     *         @OA\Schema(type="string", format="date", example="2026-08-09")
     *     ),
     *     @OA\Response(response=200, description="EOD report retrieved successfully"),
     *     @OA\Response(response=404, description="No EOD report found for date"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function show(Request $request): JsonResponse
    {
        $request->validate([
            'date' => 'required|date_format:Y-m-d',
        ]);

        $companyId = auth()->user()?->company_id;
        $date = $request->input('date');

        $report = $this->eodService->getReconciliationReport($date, $companyId);

        if (!$report) {
            return response()->json([
                'success' => false,
                'message' => "No EOD reconciliation report found for date {$date}",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $report,
        ], 200);
    }
}
