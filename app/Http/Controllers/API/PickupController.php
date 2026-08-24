<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\PickupService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *     name="Pickup",
 *     description="Warehouse Pickup & Physical Bale Release APIs"
 * )
 */
class PickupController extends Controller
{
    protected PickupService $pickupService;

    public function __construct(PickupService $pickupService)
    {
        $this->pickupService = $pickupService;
    }

    /**
     * Validate Pickup Code
     *
     * @OA\Post(
     *     path="/api/pickup/validate",
     *     tags={"Pickup"},
     *     summary="Validate buyer pickup code and return order & reserved bale items",
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"pickup_code"},
     *             @OA\Property(property="pickup_code", type="string", example="PKP-ABC12345"),
     *             @OA\Property(property="company_id", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Pickup code valid and order details retrieved"),
     *     @OA\Response(response=422, description="Invalid or expired pickup code")
     * )
     */
    public function validateCode(Request $request): JsonResponse
    {
        $request->validate([
            'pickup_code' => 'required|string',
        ]);

        $companyId = auth()->user()?->company_id ?? $request->input('company_id');

        $result = $this->pickupService->validatePickupCode(
            $request->input('pickup_code'),
            $companyId
        );

        return response()->json([
            'success' => true,
            'data'    => $result,
        ], 200);
    }

    /**
     * Release Goods upon Pickup Code Presentation
     *
     * @OA\Post(
     *     path="/api/pickup/release",
     *     tags={"Pickup"},
     *     summary="Release physical bales to buyer upon valid pickup code presentation",
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"pickup_code"},
     *             @OA\Property(property="pickup_code", type="string", example="PKP-ABC12345"),
     *             @OA\Property(property="notes", type="string", example="Bales inspected and handed to driver"),
     *             @OA\Property(property="company_id", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Bales released successfully and inventory ledger updated"),
     *     @OA\Response(response=422, description="Validation error / unpaid order / already released")
     * )
     */
    public function releaseBales(Request $request): JsonResponse
    {
        $request->validate([
            'pickup_code' => 'required|string',
            'notes'       => 'nullable|string',
        ]);

        $user = auth()->user();
        $companyId = $user?->company_id ?? $request->input('company_id');
        $staffId = auth()->id();

        $result = $this->pickupService->releaseOrderBales(
            $request->input('pickup_code'),
            $staffId,
            $companyId,
            $request->input('notes')
        );

        return response()->json($result, 200);
    }
}
