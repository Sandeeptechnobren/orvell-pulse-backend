<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class PulseDashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $service
    ) {
    }

    public function summary(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->service->summary(),
            ]);
        } catch (Throwable $e) {
            Log::error('Dashboard summary failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load dashboard data.',
            ], 500);
        }
    }
}
