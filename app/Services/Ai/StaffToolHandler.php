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
use App\Services\ContainerService;
use App\Services\SupplierManagement\SupplierService;
use App\Models\Supplier;
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
        private readonly SaleService $sales,
        private readonly ContainerService $containers,
        private readonly SupplierService $suppliers
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
                'name' => 'list_suppliers',
                'description' => 'List registered suppliers with their codes and container counts.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'register_supplier',
                'description' => 'Register a new supplier AFTER confirming the details with the staff member. The supplier code is generated automatically. Name is required; collect phone, email, city/district, state and country if offered.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Supplier/company name (required)'],
                        'phone_no' => ['type' => 'string'],
                        'email' => ['type' => 'string'],
                        'address' => ['type' => 'string'],
                        'district' => ['type' => 'string', 'description' => 'City or district'],
                        'state' => ['type' => 'string'],
                        'country' => ['type' => 'string'],
                    ],
                    'required' => ['name', 'phone_no', 'email','address'],
                ],
            ],
            [
                'name' => 'update_supplier',
                'description' => 'Update a registered supplier\'s details (name, phone, email, address, district, state, country). Only the mentioned fields change. Confirm the change with the staff member before calling.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'supplier' => ['type' => 'string', 'description' => 'Supplier code or current name'],
                        'name' => ['type' => 'string'],
                        'phone_no' => ['type' => 'string'],
                        'email' => ['type' => 'string'],
                        'address' => ['type' => 'string'],
                        'district' => ['type' => 'string'],
                        'state' => ['type' => 'string'],
                        'country' => ['type' => 'string'],
                    ],
                    'required' => ['supplier'],
                ],
            ],
            [
                'name' => 'update_container',
                'description' => 'Update a container\'s status (in_transit/arrived/received/closed), arrival date, received date or notes. Confirm before calling.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'container_code' => ['type' => 'string'],
                        'status' => ['type' => 'string', 'enum' => ['in_transit', 'arrived', 'received', 'closed']],
                        'arrival_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'received_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['container_code'],
                ],
            ],
            [
                'name' => 'update_customer',
                'description' => 'Update any customer\'s details (name, email, address, city, country) by Buyer ID or WhatsApp number. The Buyer ID and WhatsApp number themselves cannot be changed. Confirm before calling.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer' => ['type' => 'string', 'description' => 'Buyer ID or WhatsApp number'],
                        'name' => ['type' => 'string'],
                        'email' => ['type' => 'string'],
                        'address' => ['type' => 'string'],
                        'city' => ['type' => 'string'],
                        'country' => ['type' => 'string'],
                    ],
                    'required' => ['customer'],
                ],
            ],
            [
                'name' => 'list_containers',
                'description' => 'List containers with supplier, status, arrival date and current available stock. Use to find the right container before registering stock.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'register_container',
                'description' => 'Register a new container under a supplier AFTER confirming. The container code (CNT-YYYY-XXXX) is generated automatically. Then stock can be registered into it with register_stock.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'supplier' => ['type' => 'string', 'description' => 'Supplier code (e.g. ORV-2026-0001) or supplier name'],
                        'arrival_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, defaults to today'],
                        'status' => ['type' => 'string', 'enum' => ['in_transit', 'arrived', 'received'], 'description' => 'Defaults to arrived'],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['supplier','arrival_date','status'],
                ],
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
                'list_suppliers' => $this->listSuppliers(),
                'register_supplier' => $this->registerSupplier($input),
                'update_supplier' => $this->updateSupplier($input),
                'update_container' => $this->updateContainer($input),
                'update_customer' => $this->updateCustomer($input),
                'list_containers' => $this->listContainers(),
                'register_container' => $this->registerContainer($input, $staff),
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

    private function listSuppliers(): array
    {
        $suppliers = Supplier::query()
            ->withCount('containers')
            ->orderBy('name')
            ->get()
            ->map(fn ($s) => [
                'supplier_code' => $s->supplier_code,
                'name' => $s->name,
                'phone' => $s->phone_no,
                'location' => trim(implode(', ', array_filter([$s->district, $s->state, $s->country]))),
                'containers' => $s->containers_count,
            ]);

        return $suppliers->isEmpty()
            ? ['suppliers' => [], 'note' => 'No suppliers registered yet.']
            : ['suppliers' => $suppliers];
    }

    private function registerSupplier(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ['error' => 'Supplier name is required.'];
        }

        $existing = Supplier::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        if ($existing) {
            return [
                'error' => "A supplier named '{$existing->name}' already exists ({$existing->supplier_code}). Ask the staff member whether they meant that one.",
            ];
        }

        $supplier = $this->suppliers->createSupplier([
            'supplier_code' => $this->nextSupplierCode(),
            'name' => $name,
            'phone_no' => $input['phone_no'] ?? null,
            'email' => $input['email'] ?? null,
            'address_1' => $input['address'] ?? null,
            'district' => $input['district'] ?? null,
            'state' => $input['state'] ?? null,
            'country' => $input['country'] ?? null,
        ]);

        return [
            'registered' => true,
            'supplier_code' => $supplier->supplier_code,
            'name' => $supplier->name,
            'note' => 'Containers can now be registered under this supplier.',
        ];
    }

    private function updateSupplier(array $input): array
    {
        $query = trim((string) ($input['supplier'] ?? ''));
        $supplier = Supplier::where('supplier_code', $query)
            ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($query)])
            ->first();

        if (!$supplier) {
            return ['error' => "Supplier '{$query}' not found."];
        }

        // SupplierService::updateSupplier overwrites every field, so merge the
        // current values in - only the mentioned fields actually change.
        $updated = $this->suppliers->updateSupplier($supplier, [
            'supplier_code' => $supplier->supplier_code,
            'name' => $input['name'] ?? $supplier->name,
            'phone_no' => $input['phone_no'] ?? $supplier->phone_no,
            'email' => $input['email'] ?? $supplier->email,
            'address_1' => $input['address'] ?? $supplier->address_1,
            'address_2' => $supplier->address_2,
            'district' => $input['district'] ?? $supplier->district,
            'state' => $input['state'] ?? $supplier->state,
            'zip_code' => $supplier->zip_code,
            'country' => $input['country'] ?? $supplier->country,
        ]);

        return [
            'updated' => true,
            'supplier_code' => $updated->supplier_code,
            'name' => $updated->name,
            'phone' => $updated->phone_no,
            'email' => $updated->email,
            'location' => trim(implode(', ', array_filter([$updated->district, $updated->state, $updated->country]))),
        ];
    }

    private function updateContainer(array $input): array
    {
        $container = Container::where('container_id', trim((string) ($input['container_code'] ?? '')))->first();
        if (!$container) {
            return ['error' => "Container '{$input['container_code']}' not found."];
        }

        $changes = array_intersect_key(
            $input,
            array_flip(['status', 'arrival_date', 'received_date', 'notes'])
        );

        if (empty($changes)) {
            return ['error' => 'Nothing to update - provide status, arrival_date, received_date or notes.'];
        }

        if (isset($changes['status'])
            && !in_array($changes['status'], ['in_transit', 'arrived', 'received', 'closed'], true)
        ) {
            return ['error' => 'Status must be one of: in_transit, arrived, received, closed.'];
        }

        $updated = $this->containers->update($container, $changes);

        return [
            'updated' => true,
            'container_code' => $updated->container_id,
            'status' => $updated->status,
            'arrival_date' => $updated->arrival_date?->format('Y-m-d'),
            'received_date' => $updated->received_date?->format('Y-m-d'),
        ];
    }

    private function updateCustomer(array $input): array
    {
        $query = trim((string) ($input['customer'] ?? ''));
        $customer = Customer::where('buyer_id', $query)
            ->orWhere('whatsapp_number', preg_replace('/[^0-9]/', '', $query))
            ->first();

        if (!$customer) {
            return ['error' => "Customer '{$query}' not found."];
        }

        $changes = array_intersect_key(
            $input,
            array_flip(['name', 'email', 'address', 'city', 'country'])
        );

        if (empty($changes)) {
            return ['error' => 'Nothing to update - provide name, email, address, city or country.'];
        }

        $updated = app(\App\Services\CustomerManagementService::class)
            ->update($customer->uuid, $changes);

        return [
            'updated' => true,
            'buyer_id' => $updated->buyer_id,
            'name' => $updated->name,
            'city' => $updated->city,
            'email' => $updated->email,
        ];
    }

    private function listContainers(): array
    {
        $available = BaleBatch::selectRaw('container_id, SUM(qty_available) as available')
            ->groupBy('container_id')
            ->pluck('available', 'container_id');

        $containers = Container::with('supplier')
            ->orderByDesc('arrival_date')
            ->limit(20)
            ->get()
            ->map(fn ($c) => [
                'container_code' => $c->container_id,
                'supplier' => $c->supplier?->name,
                'status' => $c->status,
                'arrival_date' => $c->arrival_date?->format('Y-m-d'),
                'bales_available' => (int) ($available[$c->id] ?? 0),
            ]);

        return $containers->isEmpty()
            ? ['containers' => [], 'note' => 'No containers registered yet.']
            : ['containers' => $containers];
    }

    private function registerContainer(array $input, User $staff): array
    {
        $query = trim((string) ($input['supplier'] ?? ''));
        $supplier = Supplier::where('supplier_code', $query)
            ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($query)])
            ->first();

        if (!$supplier) {
            return ['error' => "Supplier '{$query}' not found. Use list_suppliers to see codes, or register the supplier first."];
        }

        $container = $this->containers->create([
            'supplier_id' => $supplier->id,
            'arrival_date' => $input['arrival_date'] ?? now()->toDateString(),
            'status' => $input['status'] ?? 'arrived',
            'notes' => $input['notes'] ?? null,
        ], $staff->id);

        return [
            'registered' => true,
            'container_code' => $container->container_id,
            'supplier' => $supplier->name,
            'note' => 'Stock can now be registered into this container with register_stock.',
        ];
    }

    private function nextSupplierCode(): string
    {
        $year = now()->format('Y');
        $max = Supplier::pluck('supplier_code')
            ->map(function ($code) {
                return preg_match('/(\d{1,4})$/', (string) $code, $m) ? (int) $m[1] : 0;
            })
            ->max() ?? 0;

        do {
            $code = sprintf('ORV-%s-%04d', $year, ++$max);
        } while (Supplier::where('supplier_code', $code)->exists());

        return $code;
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
