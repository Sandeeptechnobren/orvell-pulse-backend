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

    public function store(Request $request)
    {
        $request->validate([
            'customer_id'              => 'nullable|integer|exists:customers,id',
            'customer_uuid'            => 'nullable|string|exists:customers,uuid',
            'customer_whatsapp'        => 'nullable|string',
            'items'                    => 'required|array|min:1',
            'items.*.unit_price'       => 'required|numeric|min:0.01',
            'items.*.bale_ids'         => 'nullable|array',
            'items.*.bale_ids.*'       => 'integer',
            'items.*.item_category_id' => 'nullable|integer|exists:item_category,id',
            'items.*.quantity'         => 'nullable|integer|min:1',
            'tax_amount'               => 'nullable|numeric|min:0',
            'discount_amount'          => 'nullable|numeric|min:0',
            'payment_method'           => 'nullable|string|max:30',
            'notes'                    => 'nullable|string|max:2000',
        ]);

        $companyId = auth()->user()?->company_id ?? $request->input('company_id');
        $sale = $this->saleService->createSale($request->all(), $companyId);

        return response()->json([
            'success'     => true,
            'message'     => 'Sale recorded. Invoice ' . $sale['invoice']->invoice_number . ' generated.',
            'order'       => new OrderResource($sale['order']),
            'invoice'     => $sale['invoice'],
            'pickup_code' => $sale['pickup_code'],
        ], 201);
    }

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
