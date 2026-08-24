<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ExpenseService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ExpenseController extends Controller
{
    protected ExpenseService $expenseService;

    public function __construct(ExpenseService $expenseService)
    {
        $this->expenseService = $expenseService;
    }

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
