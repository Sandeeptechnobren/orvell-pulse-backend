<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ExpenseService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *     name="Expenses",
 *     description="Operational Expenses Management APIs"
 * )
 */
class ExpenseController extends Controller
{
    protected ExpenseService $expenseService;

    public function __construct(ExpenseService $expenseService)
    {
        $this->expenseService = $expenseService;
    }

    /**
     * Record a new expense
     *
     * @OA\Post(
     *     path="/api/expenses/create",
     *     tags={"Expenses"},
     *     summary="Record operational expense for company",
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"amount", "category"},
     *             @OA\Property(property="amount", type="number", example=75.00),
     *             @OA\Property(property="category", type="string", example="Fuel"),
     *             @OA\Property(property="description", type="string", example="Generator fuel for warehouse"),
     *             @OA\Property(property="expense_date", type="string", format="date", example="2026-08-09"),
     *             @OA\Property(property="receipt_image", type="string", example="https://storage.orvell.com/receipts/exp1.jpg"),
     *             @OA\Property(property="company_id", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(response=201, description="Expense recorded successfully"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'amount'        => 'required|numeric|min:0.01',
            'category'      => 'required|string',
            'description'   => 'nullable|string',
            'expense_date'  => 'nullable|date',
            'receipt_image' => 'nullable|string',
        ]);

        $user = auth()->user();
        $companyId = $user?->company_id ?? $request->input('company_id');
        $staffId = auth()->id();

        $expense = $this->expenseService->recordExpense(
            $request->all(),
            $staffId,
            $companyId
        );

        return response()->json([
            'success' => true,
            'message' => 'Expense recorded successfully',
            'data'    => $expense,
        ], 201);
    }

    /**
     * List expenses
     *
     * @OA\Get(
     *     path="/api/expenses/list",
     *     tags={"Expenses"},
     *     summary="List filtered expenses for authenticated company",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="category",
     *         in="query",
     *         description="Filter by expense category",
     *         required=false,
     *         @OA\Schema(type="string", example="Fuel")
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
     *     @OA\Response(response=200, description="Expenses list retrieved successfully"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = auth()->user()?->company_id;
        $filters = $request->only(['category', 'start_date', 'end_date']);

        $expenses = $this->expenseService->listExpenses($filters, $companyId);

        return response()->json([
            'success' => true,
            'data'    => $expenses,
        ], 200);
    }

    /**
     * Get expense aggregate summary
     *
     * @OA\Get(
     *     path="/api/expenses/summary",
     *     tags={"Expenses"},
     *     summary="Get expense summary aggregated by category for date range",
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
     *     @OA\Response(response=200, description="Expense summary retrieved successfully"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function summary(Request $request): JsonResponse
    {
        $companyId = auth()->user()?->company_id;
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $summary = $this->expenseService->getExpenseSummary($startDate, $endDate, $companyId);

        return response()->json([
            'success' => true,
            'data'    => $summary,
        ], 200);
    }
}
