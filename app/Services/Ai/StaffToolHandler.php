<?php

namespace App\Services\Ai;

use App\Models\BaleBatch;
use App\Models\Company;
use App\Models\Container;
use App\Models\Customer;
use App\Models\Item_category;
use App\Models\OrderRequest;
use App\Models\User;
use App\Services\BaleService;
use App\Services\DashboardService;
use App\Services\ExpenseService;
use App\Services\OrderRequestService;
use App\Services\PaymentService;
use App\Services\SaleService;
use Illuminate\Support\Facades\Log;
use Throwable;

class StaffToolHandler
{
    public function __construct(
        private readonly BaleService $bales,
        private readonly DashboardService $dashboard,
        private readonly ExpenseService $expenses,
        private readonly OrderRequestService $orderRequests,
        private readonly PaymentService $payments,
        private readonly SaleService $sales
    ) {
    }

    public function definitions(): array
    {
        return [
            [
                'name' => 'get_business_summary',
                'description' => 'Today\'s full business picture: sales, cash collected, outstanding receivables, pending requests, stock totals, recent orders. Use for "how are we doing", EOD questions, or any overview request.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'get_stock',
                'description' => 'Full internal stock by container and category: available, sold, released, damaged. Staff see everything, including damaged counts.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'get_categories',
                'description' => 'List bale categories with their IDs. Use to convert category names into IDs for other tools.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'register_stock',
                'description' => 'Register newly arrived bales into a container AFTER the staff member confirms container and lines. Customers are auto-notified of the arrival.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'container_code' => ['type' => 'string', 'description' => 'Container code, e.g. CNT-2026-0001'],
                        'lines' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'category_id' => ['type' => 'integer'],
                                    'quantity' => ['type' => 'integer'],
                                ],
                                'required' => ['category_id', 'quantity'],
                            ],
                        ],
                    ],
                    'required' => ['container_code', 'lines'],
                ],
            ],
            [
                'name' => 'adjust_stock',
                'description' => 'Adjust a stock batch: mark bales damaged, restore damaged, or apply a count correction. Confirm with the staff member before calling.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'container_code' => ['type' => 'string'],
                        'category_id' => ['type' => 'integer'],
                        'mark_damaged' => ['type' => 'integer', 'description' => 'Move N from available to damaged'],
                        'restore_damaged' => ['type' => 'integer', 'description' => 'Move N from damaged back to available'],
                        'correct' => ['type' => 'integer', 'description' => 'Add (positive) or remove (negative) N available bales'],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['container_code', 'category_id'],
                ],
            ],
            [
                'name' => 'get_pending_requests',
                'description' => 'Customer order requests awaiting review, with request number, customer, and requested lines.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'convert_request',
                'description' => 'Approve a pending order request: set unit prices per line and convert it into a sale (stock reserved, invoice generated, customer notified). ALWAYS read the prices and quantities back to the staff member and get a clear yes before calling.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'request_no' => ['type' => 'string', 'description' => 'e.g. REQ-0007'],
                        'lines' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'item_category_id' => ['type' => 'integer'],
                                    'quantity' => ['type' => 'integer'],
                                    'unit_price' => ['type' => 'number'],
                                ],
                                'required' => ['item_category_id', 'quantity', 'unit_price'],
                            ],
                        ],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['request_no', 'lines'],
                ],
            ],
            [
                'name' => 'decline_request',
                'description' => 'Decline a pending order request with a reason (sent to the customer on WhatsApp). Confirm the reason with the staff member first.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'request_no' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                    ],
                    'required' => ['request_no', 'reason'],
                ],
            ],
            [
                'name' => 'find_customer',
                'description' => 'Look up any customer by WhatsApp number or Buyer ID (e.g. BUY021). Staff may view any customer.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'WhatsApp number or Buyer ID'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'create_sale',
                'description' => 'Record a direct sale (walk-in or phone order) for a registered customer: lines with category, quantity and unit price. Creates the order, invoice and pickup code; stock is reserved FIFO. Read the full sale back and get a clear yes before calling.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer' => ['type' => 'string', 'description' => 'Buyer ID or WhatsApp number'],
                        'lines' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'item_category_id' => ['type' => 'integer'],
                                    'quantity' => ['type' => 'integer'],
                                    'unit_price' => ['type' => 'number'],
                                ],
                                'required' => ['item_category_id', 'quantity', 'unit_price'],
                            ],
                        ],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['customer', 'lines'],
                ],
            ],
            [
                'name' => 'record_payment',
                'description' => 'Record a cash payment against an invoice. Partial amounts allowed; when fully paid the customer automatically receives the receipt, pickup code and PDF invoice. Confirm invoice number and amount before calling.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'invoice_number' => ['type' => 'string', 'description' => 'e.g. INV-0012'],
                        'amount' => ['type' => 'number'],
                        'reference' => ['type' => 'string'],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['invoice_number', 'amount'],
                ],
            ],
            [
                'name' => 'get_pickup_status',
                'description' => 'Check a pickup code: order, customer, payment status, and whether goods can be released.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'pickup_code' => ['type' => 'string', 'description' => 'e.g. PKP-AB12CD'],
                    ],
                    'required' => ['pickup_code'],
                ],
            ],
            [
                'name' => 'confirm_release',
                'description' => 'Release goods against a pickup code - the customer is collecting them now. Only works on fully paid orders; the customer gets a release confirmation. Confirm the code with the staff member before calling.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'pickup_code' => ['type' => 'string'],
                    ],
                    'required' => ['pickup_code'],
                ],
            ],
            [
                'name' => 'record_expense',
                'description' => 'Log a business expense: amount, what it was for, and optional category (e.g. fuel, labour, customs). Confirm before calling.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'amount' => ['type' => 'number'],
                        'description' => ['type' => 'string'],
                        'category' => ['type' => 'string'],
                        'expense_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, defaults to today'],
                    ],
                    'required' => ['amount', 'description'],
                ],
            ],
            [
                'name' => 'get_expenses_summary',
                'description' => 'Expense totals, optionally for a date range.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    ],
                ],
            ],
        ];
    }

    public function execute(string $toolName, array $input, array $context): string
    {
        $staff = $this->resolveStaff($context);

        if (!$staff) {
            Log::warning('Admin-line tool call from unregistered number', [
                'tool' => $toolName,
                'whatsapp_number' => $context['whatsapp_number'] ?? null,
            ]);

            return json_encode([
                'error' => 'This number is not registered as Orvell staff. Refuse the request and say this line is for staff only.',
            ]);
        }

        try {
            $result = match ($toolName) {
                'get_business_summary' => $this->dashboard->summary(),
                'get_stock' => ['stock' => $this->bales->stockSummary()],
                'get_categories' => ['categories' => Item_category::select('id', 'category_name')->orderBy('category_name')->get()],
                'register_stock' => $this->registerStock($input),
                'adjust_stock' => $this->adjustStock($input),
                'get_pending_requests' => $this->pendingRequests(),
                'convert_request' => $this->convertRequest($input, $staff),
                'decline_request' => $this->declineRequest($input, $staff),
                'find_customer' => $this->findCustomer($input),
                'create_sale' => $this->createSale($input, $staff),
                'record_payment' => $this->recordPayment($input, $staff),
                'get_pickup_status' => $this->pickupStatus($input),
                'confirm_release' => $this->confirmRelease($input, $staff),
                'record_expense' => $this->recordExpense($input, $staff),
                'get_expenses_summary' => $this->expenses->getExpenseSummary(
                    $input['start_date'] ?? null,
                    $input['end_date'] ?? null
                ),
                default => ['error' => "Unknown tool: {$toolName}"],
            };
        } catch (\Illuminate\Validation\ValidationException $e) {
            $result = ['error' => collect($e->errors())->flatten()->first()];
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $result = ['error' => $e->getMessage() ?: 'Record not found.'];
        } catch (\RuntimeException $e) {
            $result = ['error' => $e->getMessage()];
        } catch (Throwable $e) {
            Log::error('Staff tool execution failed', [
                'tool' => $toolName,
                'staff_id' => $staff->id,
                'error' => $e->getMessage(),
            ]);
            $result = ['error' => 'The operation failed unexpectedly. The team has been notified via logs.'];
        }

        Log::info('Staff tool executed', [
            'line' => 'admin',
            'tool' => $toolName,
            'staff_id' => $staff->id,
            'staff_name' => $staff->name,
        ]);

        return json_encode($result);
    }

    public function resolveStaff(array $context): ?User
    {
        $number = $context['whatsapp_number'] ?? null;

        if (!$number) {
            return null;
        }

        return User::where('whatsapp_number', $number)->first();
    }

    private function registerStock(array $input): array
    {
        $container = Container::where('container_id', trim((string) ($input['container_code'] ?? '')))->first();
        if (!$container) {
            return ['error' => "Container '{$input['container_code']}' not found. Use the exact code, e.g. CNT-2026-0001."];
        }

        $batches = $this->bales->bulkCreate($container->id, $input['lines'] ?? []);
        $total = collect($input['lines'] ?? [])->sum('quantity');

        return [
            'registered' => true,
            'container' => $container->container_id,
            'total_bales' => $total,
            'note' => 'Customers have been notified of the arrival.',
        ];
    }

    private function adjustStock(array $input): array
    {
        $container = Container::where('container_id', trim((string) ($input['container_code'] ?? '')))->first();
        if (!$container) {
            return ['error' => "Container '{$input['container_code']}' not found."];
        }

        $batch = BaleBatch::where('container_id', $container->id)
            ->where('category_id', (int) ($input['category_id'] ?? 0))
            ->first();
        if (!$batch) {
            return ['error' => 'No stock batch for that container and category.'];
        }

        $updated = $this->bales->adjust($batch->id, array_intersect_key(
            $input,
            array_flip(['mark_damaged', 'restore_damaged', 'correct', 'notes'])
        ));

        return [
            'adjusted' => true,
            'container' => $container->container_id,
            'available' => $updated->qty_available,
            'damaged' => $updated->qty_damaged,
            'total' => $updated->qty_total,
        ];
    }

    private function pendingRequests(): array
    {
        $requests = OrderRequest::with(['customer', 'items.category'])
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->limit(15)
            ->get()
            ->map(fn ($r) => [
                'request_no' => $r->request_no,
                'customer' => $r->customer?->name,
                'buyer_id' => $r->customer?->buyer_id,
                'requested_at' => $r->created_at?->toDateTimeString(),
                'lines' => $r->items->map(fn ($i) => [
                    'category_id' => $i->category_id,
                    'category' => $i->category?->category_name,
                    'quantity' => $i->quantity,
                ]),
                'note' => $r->notes,
            ]);

        return $requests->isEmpty()
            ? ['pending' => [], 'note' => 'No pending requests.']
            : ['pending' => $requests];
    }

    private function convertRequest(array $input, User $staff): array
    {
        $uuid = OrderRequest::where('request_no', trim((string) ($input['request_no'] ?? '')))->value('uuid');
        if (!$uuid) {
            return ['error' => "Request '{$input['request_no']}' not found."];
        }

        $converted = $this->orderRequests->convert($uuid, $input['lines'] ?? [], $staff->id, $input['notes'] ?? null);

        return [
            'converted' => true,
            'request_no' => $converted->request_no,
            'invoice_number' => $converted->order?->invoice_code,
            'total_amount' => $converted->order?->total_amount,
            'note' => 'Customer notified on WhatsApp with the confirmed quantities and total.',
        ];
    }

    private function declineRequest(array $input, User $staff): array
    {
        $uuid = OrderRequest::where('request_no', trim((string) ($input['request_no'] ?? '')))->value('uuid');
        if (!$uuid) {
            return ['error' => "Request '{$input['request_no']}' not found."];
        }

        $declined = $this->orderRequests->decline($uuid, (string) ($input['reason'] ?? ''), $staff->id);

        return [
            'declined' => true,
            'request_no' => $declined->request_no,
            'note' => 'Customer notified with the reason.',
        ];
    }

    private function findCustomer(array $input): array
    {
        $query = trim((string) ($input['query'] ?? ''));
        $customer = Customer::where('buyer_id', $query)
            ->orWhere('whatsapp_number', preg_replace('/[^0-9]/', '', $query))
            ->first();

        if (!$customer) {
            return ['found' => false];
        }

        return [
            'found' => true,
            'buyer_id' => $customer->buyer_id,
            'name' => $customer->name,
            'whatsapp_number' => $customer->whatsapp_number,
            'city' => $customer->city,
            'onboarding_complete' => (int) $customer->onboarding_status === 1,
        ];
    }

    private function createSale(array $input, User $staff): array
    {
        $query = trim((string) ($input['customer'] ?? ''));
        $customer = Customer::where('buyer_id', $query)
            ->orWhere('whatsapp_number', preg_replace('/[^0-9]/', '', $query))
            ->first();

        if (!$customer) {
            return ['error' => "Customer '{$query}' not found. They must be registered first."];
        }

        $sale = $this->sales->createSale([
            'customer_id' => $customer->id,
            'items' => $input['lines'] ?? [],
            'notes' => $input['notes'] ?? "Sale recorded via admin line by {$staff->name}",
        ]);

        return [
            'sale_recorded' => true,
            'customer' => $customer->name,
            'invoice_number' => $sale['invoice']->invoice_number,
            'total_amount' => $sale['invoice']->total_amount,
            'pickup_code' => $sale['pickup_code'],
            'note' => 'Invoice created. Record the payment to trigger the pickup code message to the customer.',
        ];
    }

    private function recordPayment(array $input, User $staff): array
    {
        $result = $this->payments->recordCashPayment([
            'invoice_number' => trim((string) ($input['invoice_number'] ?? '')),
            'amount' => (float) ($input['amount'] ?? 0),
            'payment_reference' => $input['reference'] ?? null,
            'notes' => $input['notes'] ?? null,
        ], $staff->id);

        return [
            'payment_recorded' => true,
            'invoice_number' => $result['invoice']->invoice_number,
            'payment_status' => $result['payment_status'],
            'outstanding_balance' => $result['outstanding_balance'],
            'note' => $result['payment_status'] === 'paid'
                ? 'Fully paid - the customer automatically receives the receipt, pickup code and PDF invoice.'
                : 'Partial payment recorded; the customer was notified of the outstanding balance.',
        ];
    }

    private function pickupStatus(array $input): array
    {
        $order = $this->sales->getSaleByPickupCode(strtoupper(trim((string) ($input['pickup_code'] ?? ''))));
        if (!$order) {
            return ['error' => 'No order found for that pickup code.'];
        }

        return [
            'order_no' => $order->order_no,
            'customer' => $order->customer?->name,
            'invoice_number' => $order->invoice_code,
            'total_amount' => $order->total_amount,
            'payment_status' => $order->payment_status,
            'pickup_status' => $order->pickup_status,
            'releasable' => $order->payment_status === 'paid' && $order->pickup_status !== 'released',
        ];
    }

    private function confirmRelease(array $input, User $staff): array
    {
        $order = $this->sales->confirmPickup(strtoupper(trim((string) ($input['pickup_code'] ?? ''))), $staff->id);

        return [
            'released' => true,
            'order_no' => $order->order_no,
            'customer' => $order->customer?->name,
            'note' => 'Release logged under your name; the customer received the confirmation.',
        ];
    }

    private function recordExpense(array $input, User $staff): array
    {
        $companyId = Company::query()->value('id');
        if (!$companyId) {
            return ['error' => 'No company is configured in the system - expenses cannot be recorded yet. Ask the administrator to create the company record.'];
        }

        $expense = $this->expenses->recordExpense([
            'amount' => (float) ($input['amount'] ?? 0),
            'description' => $input['description'] ?? 'Warehouse expense',
            'category' => $input['category'] ?? 'miscellaneous',
            'expense_date' => $input['expense_date'] ?? now()->toDateString(),
        ], $staff->id, $companyId);

        return [
            'expense_recorded' => true,
            'amount' => (float) $expense->amount,
            'category' => $expense->category,
            'date' => $expense->expense_date,
        ];
    }
}
