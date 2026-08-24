<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Buyer;
use App\Models\Bale;
use App\Models\Invoice;
use App\Models\Item_category;
use App\Models\StockManagement;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaleService
{
    protected InventoryService $inventoryService;
    protected InvoiceService $invoiceService;
    protected AuditService $auditService;

    public function __construct(
        InventoryService $inventoryService,
        InvoiceService $invoiceService,
        AuditService $auditService
    ) {
        $this->inventoryService = $inventoryService;
        $this->invoiceService = $invoiceService;
        $this->auditService = $auditService;
    }

    /**
     * Coordinate complete Sale workflow: Buyer validation -> Bale reservation -> Order -> Line Items -> Invoice -> Finalization -> Pickup Code.
     * Guaranteed transaction-safe; fails safely with full rollback if any check fails.
     *
     * @param array $data
     * @param int|null $companyId
     * @return array
     * @throws ValidationException
     */
    public function createSale(array $data, ?int $companyId = null): array
    {
        return DB::transaction(function () use ($data, $companyId) {
            $targetCompanyId = $companyId ?? ($data['company_id'] ?? auth()->user()?->company_id);

            if (!$targetCompanyId) {
                throw ValidationException::withMessages([
                    'company_id' => ['Company ID is required to process a sale.'],
                ]);
            }

            // 1. Validate / Resolve Buyer (Authoritative PULSE identity)
            $buyer = null;
            if (!empty($data['buyer_id'])) {
                $buyer = Buyer::where('id', $data['buyer_id'])->first();
                if (!$buyer || ($buyer->company_id && $buyer->company_id != $targetCompanyId)) {
                    throw ValidationException::withMessages([
                        'buyer_id' => ['Buyer not found or unauthorized for this company.'],
                    ]);
                }
            } elseif (!empty($data['buyer_phone'])) {
                $buyer = Buyer::firstOrCreate(
                    [
                        'whatsapp_number' => $data['buyer_phone'],
                        'company_id'      => $targetCompanyId,
                    ],
                    [
                        'name'           => $data['buyer_name'] ?? 'PULSE Wholesale Buyer',
                        'category'       => 'REGULAR',
                        'priority_level' => 'STANDARD',
                    ]
                );
            }

            if (!$buyer) {
                throw ValidationException::withMessages([
                    'buyer' => ['Valid Buyer identification is required.'],
                ]);
            }

            // 2. Resolve and Lock Physical Bales
            $requestedBaleIds = [];
            $lineItemsData = [];
            $totalQuantity = 0;
            $calculatedTotal = 0.00;

            $items = $data['items'] ?? [];
            if (empty($items)) {
                throw ValidationException::withMessages([
                    'items' => ['Sale must include at least one item or garment category.'],
                ]);
            }

            foreach ($items as $item) {
                if (!empty($item['bale_ids'])) {
                    // Direct Bale selection
                    $baleIds = (array) $item['bale_ids'];
                    $bales = Bale::whereIn('id', $baleIds)
                        ->where('company_id', $targetCompanyId)
                        ->lockForUpdate()
                        ->get();

                    if ($bales->count() !== count($baleIds)) {
                        throw ValidationException::withMessages([
                            'items' => ['One or more requested bales are unavailable or belong to another company.'],
                        ]);
                    }

                    foreach ($bales as $b) {
                        if ($b->status !== 'available') {
                            throw ValidationException::withMessages([
                                'items' => ["Bale {$b->bale_code} is currently '{$b->status}' and cannot be reserved."],
                            ]);
                        }
                        $requestedBaleIds[] = $b->id;
                        $unitPrice = round((float) ($item['unit_price'] ?? $b->selling_price), 2);
                        $lineItemsData[] = [
                            'bale_id'          => $b->id,
                            'container_id'     => $b->container_id,
                            'item_category_id' => $b->item_category_id,
                            'item_description' => $item['item_description'] ?? ($b->category?->category_name ?? 'Garment Bale'),
                            'quantity'         => 1,
                            'unit_price'       => $unitPrice,
                            'total_price'      => $unitPrice,
                        ];
                        $calculatedTotal = round($calculatedTotal + $unitPrice, 2);
                        $totalQuantity++;
                    }
                } elseif (!empty($item['item_category_id']) && !empty($item['quantity'])) {
                    // Category-based automatic bale allocation
                    $categoryId = (int) $item['item_category_id'];
                    $qty = (int) $item['quantity'];

                    $availableBales = Bale::where('item_category_id', $categoryId)
                        ->where('company_id', $targetCompanyId)
                        ->where('status', 'available')
                        ->lockForUpdate()
                        ->limit($qty)
                        ->get();

                    if ($availableBales->count() < $qty) {
                        $catName = Item_category::find($categoryId)?->category_name ?? "Category #{$categoryId}";
                        throw ValidationException::withMessages([
                            'items' => ["Insufficient stock for {$catName}. Requested: {$qty}, Available: {$availableBales->count()}."],
                        ]);
                    }

                    $unitPrice = round((float) ($item['unit_price'] ?? ($availableBales->first()->selling_price ?: 0.00)), 2);

                    foreach ($availableBales as $b) {
                        $requestedBaleIds[] = $b->id;
                        $lineItemsData[] = [
                            'bale_id'          => $b->id,
                            'container_id'     => $b->container_id,
                            'item_category_id' => $b->item_category_id,
                            'item_description' => $item['item_description'] ?? ($b->category?->category_name ?? 'Garment Bale'),
                            'quantity'         => 1,
                            'unit_price'       => $unitPrice,
                            'total_price'      => $unitPrice,
                        ];
                        $calculatedTotal = round($calculatedTotal + $unitPrice, 2);
                        $totalQuantity++;
                    }
                }
            }

            if (empty($requestedBaleIds)) {
                throw ValidationException::withMessages([
                    'items' => ['No physical bales could be allocated for this sale.'],
                ]);
            }

            $authUser = auth()->user();
            $isStaffUser = $authUser instanceof \App\Models\User;
            $salespersonId = $isStaffUser ? $authUser->id : null;
            $clientId = $authUser instanceof \App\Models\Client ? $authUser->id : ($buyer->client_id ?? null);
            $createdById = $isStaffUser ? $authUser->id : null;

            // 3. Generate unique pickup code #PKP-XXXX
            $pickupCode = 'PKP-' . strtoupper(Str::random(6));

            // 4. Create Order record
            $order = Order::create([
                'buyer_id'          => $buyer->id,
                'customer_name'     => $buyer->name,
                'company_id'        => $targetCompanyId,
                'client_id'         => $clientId,
                'salesperson_id'    => $salespersonId,
                'order_quantity'    => $totalQuantity,
                'total_amount'      => $calculatedTotal,
                'order_amount'      => $calculatedTotal,
                'currency'          => $data['currency'] ?? 'GHS',
                'status'            => 'pending',
                'payment_status'    => 'pending',
                'pickup_code'       => $pickupCode,
                'pickup_status'     => 'pending',
                'payment_method'    => $data['payment_method'] ?? 'cash',
                'payment_reference' => $data['payment_reference'] ?? null,
                'notes'             => $data['notes'] ?? null,
                'created_by'        => $createdById,
            ]);

            // 5. Atomically reserve physical Bales
            $reservedBales = $this->inventoryService->reserveBales(
                $requestedBaleIds,
                $order->id,
                $createdById,
                $targetCompanyId
            );

            // 6. Generate and finalize Invoice with line item snapshots
            $invoice = $this->invoiceService->createInvoice([
                'order_id'        => $order->id,
                'buyer_id'        => $buyer->id,
                'company_id'      => $targetCompanyId,
                'client_id'       => $order->client_id,
                'tax_amount'      => $data['tax_amount'] ?? 0.00,
                'discount_amount' => $data['discount_amount'] ?? 0.00,
                'items'           => $lineItemsData,
                'status'          => 'finalized', // Immediately finalized upon sale confirmation
                'notes'           => $order->notes,
            ], $targetCompanyId);

            // Link invoice_code back to Order
            $order->update([
                'invoice_code' => $invoice->invoice_number,
            ]);

            // 7. Audit log
            $this->auditService->log(
                action: 'sale.create',
                auditable: $order,
                newValues: [
                    'order_no'       => $order->order_no,
                    'invoice_number' => $invoice->invoice_number,
                    'pickup_code'    => $order->pickup_code,
                    'total_amount'   => $order->total_amount,
                    'bale_count'     => count($reservedBales),
                ],
                companyId: $targetCompanyId,
                userId: auth()->id()
            );

            return [
                'order'          => $order->load(['buyer', 'invoice.items']),
                'invoice'        => $invoice,
                'reserved_bales' => $reservedBales,
                'pickup_code'    => $order->pickup_code,
            ];
        });
    }

    /**
     * Find order by unique pickup code.
     */
    public function getSaleByPickupCode(string $pickupCode, ?int $companyId = null): ?Order
    {
        $query = Order::where('pickup_code', $pickupCode)->with(['buyer', 'invoice.items', 'reservedBales', 'company']);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        return $query->first();
    }
}
