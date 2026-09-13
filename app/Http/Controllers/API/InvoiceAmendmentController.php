<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\InvoiceAmendmentService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class InvoiceAmendmentController extends Controller
{
    protected InvoiceAmendmentService $amendmentService;

    public function __construct(InvoiceAmendmentService $amendmentService)
    {
        $this->amendmentService = $amendmentService;
    }

    public function requestAmendment(Request $request): JsonResponse
    {
        $request->validate([
            'invoice_id'        => 'required|exists:invoices,id',
            'reason'            => 'required|string|max:500',
            'requested_changes' => 'required|array',
        ]);

        $user = auth()->user();
        $companyId = $user?->company_id ?? $request->input('company_id');
        $requestedById = auth()->id();

        $amendmentRequest = $this->amendmentService->requestAmendment(
            $request->input('invoice_id'),
            $request->all(),
            $requestedById,
            $companyId
        );

        return response()->json([
            'success' => true,
            'message' => 'Invoice amendment request submitted successfully for review',
            'data'    => $amendmentRequest,
        ], 201);
    }

    public function approveAmendment(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();

        // RBAC Authorization check
        if ($user instanceof User) {
            $isAuthorized = false;
            if (method_exists($user, 'hasRole')) {
                $isAuthorized = $user->hasRole(['Admin', 'admin', 'Super Admin', 'super-admin', 'Manager', 'manager']);
            }
            if (!$isAuthorized && method_exists($user, 'hasRole') && $user->hasRole(['Salesperson', 'salesperson', 'Staff', 'staff', 'Cashier', 'cashier'])) {
                app(\App\Services\AuditService::class)->log(
                    action: 'auth.access_denied',
                    auditable: $user,
                    newValues: ['endpoint' => "/api/invoices/amendment/{$id}/approve", 'reason' => 'Unauthorized role for amendment approval'],
                    companyId: $user->company_id,
                    userId: $user->id
                );
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only Admins and Managers can approve invoice amendments.',
                ], 403);
            }
        }

        $companyId = $user?->company_id ?? $request->input('company_id');
        $reviewerId = auth()->id();

        $newInvoice = $this->amendmentService->approveAmendment($id, $reviewerId, $companyId);

        return response()->json([
            'success' => true,
            'message' => 'Invoice amendment approved and new version created successfully',
            'invoice' => $newInvoice,
        ], 200);
    }

    public function rejectAmendment(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        $user = auth()->user();

        // RBAC Authorization check
        if ($user instanceof User) {
            $isAuthorized = false;
            if (method_exists($user, 'hasRole')) {
                $isAuthorized = $user->hasRole(['Admin', 'admin', 'Super Admin', 'super-admin', 'Manager', 'manager']);
            }
            if (!$isAuthorized && method_exists($user, 'hasRole') && $user->hasRole(['Salesperson', 'salesperson', 'Staff', 'staff', 'Cashier', 'cashier'])) {
                app(\App\Services\AuditService::class)->log(
                    action: 'auth.access_denied',
                    auditable: $user,
                    newValues: ['endpoint' => "/api/invoices/amendment/{$id}/reject", 'reason' => 'Unauthorized role for amendment rejection'],
                    companyId: $user->company_id,
                    userId: $user->id
                );
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only Admins and Managers can reject invoice amendments.',
                ], 403);
            }
        }

        $companyId = $user?->company_id ?? $request->input('company_id');
        $reviewerId = auth()->id();

        $rejectedRequest = $this->amendmentService->rejectAmendment(
            $id,
            $request->input('rejection_reason'),
            $reviewerId,
            $companyId
        );

        return response()->json([
            'success' => true,
            'message' => 'Invoice amendment request rejected successfully',
            'data'    => $rejectedRequest,
        ], 200);
    }

    public function listAmendments(Request $request): JsonResponse
    {
        $companyId = auth()->user()?->company_id;
        $status = $request->input('status');

        $requests = $this->amendmentService->listAmendmentRequests($status, $companyId);

        return response()->json([
            'success' => true,
            'data'    => $requests,
        ]);
    }
}
