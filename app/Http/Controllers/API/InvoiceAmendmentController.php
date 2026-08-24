<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\InvoiceAmendmentService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *     name="Invoice Amendment",
 *     description="Invoice amendment and approval workflow APIs"
 * )
 */
class InvoiceAmendmentController extends Controller
{
    protected InvoiceAmendmentService $amendmentService;

    public function __construct(InvoiceAmendmentService $amendmentService)
    {
        $this->amendmentService = $amendmentService;
    }

    /**
     * Submit Invoice Amendment Request
     *
     * @OA\Post(
     *     path="/api/invoices/amendment-request",
     *     tags={"Invoice Amendment"},
     *     summary="Submit request to amend an immutable finalized invoice",
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"invoice_id", "reason", "requested_changes"},
     *             @OA\Property(property="invoice_id", type="integer", example=1),
     *             @OA\Property(property="reason", type="string", example="Price discount approved by manager"),
     *             @OA\Property(
     *                 property="requested_changes",
     *                 type="object",
     *                 example={"discount_amount": 25.00}
     *             ),
     *             @OA\Property(property="company_id", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(response=201, description="Invoice amendment request submitted successfully"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
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

    /**
     * Approve Invoice Amendment Request (Admin / Manager Only)
     *
     * @OA\Post(
     *     path="/api/invoices/amendment/{id}/approve",
     *     tags={"Invoice Amendment"},
     *     summary="Approve invoice amendment request and generate versioned invoice",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Amendment Request ID",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="company_id", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Invoice amendment approved and new version created"),
     *     @OA\Response(response=403, description="Unauthorized. Only Admins and Managers can approve")
     * )
     */
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

    /**
     * Reject Invoice Amendment Request (Admin / Manager Only)
     *
     * @OA\Post(
     *     path="/api/invoices/amendment/{id}/reject",
     *     tags={"Invoice Amendment"},
     *     summary="Reject invoice amendment request with stated reason",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Amendment Request ID",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"rejection_reason"},
     *             @OA\Property(property="rejection_reason", type="string", example="Amendment rejected due to missing physical bale verification"),
     *             @OA\Property(property="company_id", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Invoice amendment request rejected successfully"),
     *     @OA\Response(response=403, description="Unauthorized. Only Admins and Managers can reject")
     * )
     */
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

    /**
     * List Invoice Amendment Requests
     *
     * @OA\Get(
     *     path="/api/invoices/amendment/list",
     *     tags={"Invoice Amendment"},
     *     summary="List invoice amendment requests for company",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by request status (pending, approved, rejected)",
     *         required=false,
     *         @OA\Schema(type="string", example="pending")
     *     ),
     *     @OA\Response(response=200, description="Amendment requests retrieved successfully"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
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
