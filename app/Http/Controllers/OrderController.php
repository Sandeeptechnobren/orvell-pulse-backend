<?php

namespace App\Http\Controllers;

use App\Services\OrderService;
use App\Services\SaleService;
use App\Http\Resources\OrderResource;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    protected OrderService $service;
    protected SaleService $saleService;

    public function __construct(OrderService $service, SaleService $saleService)
    {
        $this->service = $service;
        $this->saleService = $saleService;
    }

    /**
     * @OA\Post(
     *     path="/api/orders/create",
     *     tags={"Orders"},
     *     summary="Create new sale / order",
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"buyer_id", "items"},
     *             @OA\Property(property="buyer_id", type="integer", example=1),
     *             @OA\Property(property="items", type="array", @OA\Items(
     *                 @OA\Property(property="item_category_id", type="integer", example=1),
     *                 @OA\Property(property="quantity", type="integer", example=2)
     *             ))
     *         )
     *     ),
     *     @OA\Response(response=201, description="Order created successfully")
     * )
     */
    public function store(Request $request)
    {
        $request->validate([
            'buyer_id'    => 'nullable|exists:buyers,id',
            'buyer_phone' => 'nullable|string',
            'items'       => 'required|array|min:1',
        ]);

        $companyId = auth()->user()?->company_id ?? $request->input('company_id');
        $sale = $this->saleService->createSale($request->all(), $companyId);

        return response()->json([
            'success'     => true,
            'message'     => 'Order created successfully',
            'order'       => new OrderResource($sale['order']),
            'invoice'     => $sale['invoice'],
            'pickup_code' => $sale['pickup_code'],
        ], 201);
    }

    /**
     * @OA\Get(
     *     path="/api/orders/list",
     *     tags={"Orders"},
     *     summary="Get all orders",
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Orders fetched successfully"
     *     )
     * )
     */
    public function index()
    {
        $orders = $this->service->list(); // must return paginate()

        return response()->json([
            'success' => true,
            'data' => OrderResource::collection($orders),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page'    => $orders->lastPage(),
                'per_page'     => $orders->perPage(),
                'total'        => $orders->total(),
            ],
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/orders/show/{uuid}",
     *     tags={"Orders"},
     *     summary="Get order by UUID",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="uuid",
     *         in="path",
     *         required=true,
     *         description="Order UUID"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Order fetched successfully"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Order not found"
     *     )
     * )
     */
    public function show(string $uuid)
    {
        $order = $this->service->getByUuid($uuid);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new OrderResource($order),
        ]);
    }
}
