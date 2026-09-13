<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\PickupService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PickupController extends Controller
{
    protected PickupService $pickupService;

    public function __construct(PickupService $pickupService)
    {
        $this->pickupService = $pickupService;
    }

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
