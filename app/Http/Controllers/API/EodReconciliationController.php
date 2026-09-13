<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\EodReconciliationService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EodReconciliationController extends Controller
{
    protected EodReconciliationService $eodService;

    public function __construct(EodReconciliationService $eodService)
    {
        $this->eodService = $eodService;
    }

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
