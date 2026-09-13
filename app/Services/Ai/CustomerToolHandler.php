<?php

namespace App\Services\Ai;

use App\Models\Item_category;
use App\Services\CustomerManagementService;
use Illuminate\Support\Facades\Log;
use Throwable;

class CustomerToolHandler
{
    public function __construct(
        private readonly CustomerManagementService $customers,
        private readonly \App\Services\OrderRequestService $orderRequests
    ) {
    }

    /**
     * Tool definitions sent to Claude. Note: no tool accepts a phone number —
     * identity always comes from the webhook context, never from the model.
     */
    public function definitions(): array
    {
        return [
            [
                'name' => 'find_customer',
                'description' => 'Look up the customer who is currently messaging, by their WhatsApp number. Call this FIRST in every conversation to know if they are already registered. Returns their profile, or found=false if they are new.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                ],
            ],
            [
                'name' => 'get_categories',
                'description' => 'List the bale categories Orvell sells (id and name). Use this when asking the buyer which categories they are interested in, and to convert their answers into category IDs for register_customer.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                ],
            ],
            [
                'name' => 'get_available_stock',
                'description' => 'Live stock availability by bale category (category name + how many bales are available now, and when the newest stock arrived). Use this whenever a customer asks what is in stock, whether a category is available, or what arrived recently. Quantities only — prices are confirmed by the sales team, so never invent prices.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                ],
            ],
            [
                'name' => 'register_customer',
                'description' => 'Register the messaging buyer as a new customer AFTER collecting and confirming ALL required details: full name, city/location, and at least one preferred bale category. Returns the generated Buyer ID. Do not call with partial data — collect everything first, one question at a time. Only call once per customer — check find_customer first.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Full name of the buyer (required)'],
                        'city' => ['type' => 'string', 'description' => 'City or town, e.g. Accra (required)'],
                        'preferred_categories' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'IDs of bale categories the buyer is interested in, from get_categories (required, at least one)',
                        ],
                        'email' => ['type' => 'string', 'description' => 'Email address, if provided'],
                        'address' => ['type' => 'string', 'description' => 'Street address or market location, if provided'],
                        'country' => ['type' => 'string', 'description' => 'Country, default Ghana'],
                    ],
                    'required' => ['name', 'city', 'preferred_categories'],
                ],
            ],
            [
                'name' => 'create_order_request',
                'description' => 'Place an order request for the messaging customer AFTER they confirmed the lines. Requires completed onboarding. The request has NO prices and reserves NO stock - the sales team confirms price and availability, so never promise a price or that goods are held. Returns the request number and current availability per line.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'lines' => [
                            'type' => 'array',
                            'description' => 'Requested lines',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'category_id' => ['type' => 'integer', 'description' => 'Category ID from get_categories or get_available_stock'],
                                    'quantity' => ['type' => 'integer', 'description' => 'Number of bales requested'],
                                ],
                                'required' => ['category_id', 'quantity'],
                            ],
                        ],
                        'note' => ['type' => 'string', 'description' => 'Optional note from the customer'],
                    ],
                    'required' => ['lines'],
                ],
            ],
            [
                'name' => 'get_my_order_requests',
                'description' => 'The messaging customer\'s recent order requests with status (pending / converted / declined / cancelled), and for converted ones the invoice number, total, payment status and - once paid - the pickup code. Use when they ask about their order, invoice, or pickup code.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                ],
            ],
            [
                'name' => 'cancel_order_request',
                'description' => 'Cancel one of the messaging customer\'s own order requests. Only pending requests can be cancelled - confirmed orders must go through the team. Ask the customer to confirm before calling.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'request_no' => ['type' => 'string', 'description' => 'The request number, e.g. REQ-0001'],
                    ],
                    'required' => ['request_no'],
                ],
            ],
            [
                'name' => 'update_customer',
                'description' => 'Update the messaging customer\'s own profile (name, email, address, city, preferred categories) after they confirm the change. Cannot change the Buyer ID or WhatsApp number.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'email' => ['type' => 'string'],
                        'address' => ['type' => 'string'],
                        'city' => ['type' => 'string'],
                        'country' => ['type' => 'string'],
                        'preferred_categories' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Execute a tool call. $context carries the trusted identity from the
     * WhatsApp webhook: ['whatsapp_number' => ..., 'wa_id' => ...].
     */
    public function execute(string $toolName, array $input, array $context): string
    {
        try {
            $result = match ($toolName) {
                'find_customer' => $this->findCustomer($context),
                'get_categories' => $this->getCategories(),
                'get_available_stock' => $this->getAvailableStock(),
                'create_order_request' => $this->createOrderRequest($input, $context),
                'get_my_order_requests' => $this->getMyOrderRequests($context),
                'cancel_order_request' => $this->cancelOrderRequest($input, $context),
                'register_customer' => $this->registerCustomer($input, $context),
                'update_customer' => $this->updateCustomer($input, $context),
                default => ['error' => "Unknown tool: {$toolName}"],
            };
        } catch (Throwable $e) {
            Log::error('AI tool execution failed', [
                'tool' => $toolName,
                'context' => $context,
                'error' => $e->getMessage(),
            ]);
            $result = ['error' => 'The operation failed. Ask the customer to try again shortly.'];
        }

        Log::info('AI tool executed', [
            'tool' => $toolName,
            'whatsapp_number' => $context['whatsapp_number'] ?? null,
        ]);

        return json_encode($result);
    }

    private function findCustomer(array $context): array
    {
        $customer = $this->customers->findByWhatsappNumber($context['whatsapp_number']);

        if (!$customer) {
            return ['found' => false];
        }

        $missing = $this->missingRequiredFields($customer);

        return [
            'found' => true,
            'buyer_id' => $customer->buyer_id,
            'name' => $customer->name,
            'email' => $customer->email,
            'city' => $customer->city,
            'country' => $customer->country,
            'preferred_categories' => $customer->preferred_categories,
            'onboarding_complete' => empty($missing),
            'missing_fields' => $missing,
            'note' => empty($missing)
                ? null
                : 'Onboarding is incomplete. Collect the missing fields via update_customer before helping with anything else.',
        ];
    }

    private function getCategories(): array
    {
        return [
            'categories' => Item_category::query()
                ->select('id', 'category_name')
                ->orderBy('category_name')
                ->get()
                ->toArray(),
        ];
    }

    private function getAvailableStock(): array
    {
        $stock = \App\Models\BaleBatch::query()
            ->join('item_category', 'item_category.id', '=', 'tbl_bale_batches.category_id')
            ->where('tbl_bale_batches.qty_available', '>', 0)
            ->selectRaw('
                item_category.category_name,
                SUM(tbl_bale_batches.qty_available) as available,
                MAX(tbl_bale_batches.arrival_date) as latest_arrival
            ')
            ->groupBy('item_category.category_name')
            ->orderBy('item_category.category_name')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category_name,
                'bales_available' => (int) $row->available,
                'latest_arrival' => $row->latest_arrival,
            ])
            ->all();

        if (empty($stock)) {
            return [
                'in_stock' => [],
                'note' => 'No stock is currently available. New shipments arrive regularly.',
            ];
        }

        return ['in_stock' => $stock];
    }

    private function registerCustomer(array $input, array $context): array
    {
        $existing = $this->customers->findByWhatsappNumber($context['whatsapp_number']);
        if ($existing) {
            return [
                'error' => 'This customer is already registered.',
                'buyer_id' => $existing->buyer_id,
            ];
        }

        $categories = $this->validCategoryIds($input['preferred_categories'] ?? []);

        $missing = [];
        if (empty(trim($input['name'] ?? ''))) {
            $missing[] = 'name';
        }
        if (empty(trim($input['city'] ?? ''))) {
            $missing[] = 'city';
        }
        if (empty($categories)) {
            $missing[] = 'preferred_categories';
        }

        if (!empty($missing)) {
            return [
                'error' => 'Cannot register yet - required fields missing: ' . implode(', ', $missing) . '. Ask the customer for these first.',
            ];
        }

        $customer = $this->customers->create([
            'name' => $input['name'],
            'whatsapp_number' => $context['whatsapp_number'],
            'wa_id' => $context['wa_id'] ?? null,
            'email' => $input['email'] ?? null,
            'address' => $input['address'] ?? null,
            'city' => $input['city'],
            'country' => $input['country'] ?? 'Ghana',
            'preferred_categories' => $categories,
            'onboarding_status' => 1,
        ]);

        return [
            'registered' => true,
            'buyer_id' => $customer->buyer_id,
            'name' => $customer->name,
        ];
    }

    private function updateCustomer(array $input, array $context): array
    {
        $customer = $this->customers->findByWhatsappNumber($context['whatsapp_number']);
        if (!$customer) {
            return ['error' => 'Customer not found. Register them first.'];
        }

        $allowed = array_intersect_key(
            $input,
            array_flip(['name', 'email', 'address', 'city', 'country', 'preferred_categories'])
        );

        if (isset($allowed['preferred_categories'])) {
            $allowed['preferred_categories'] = $this->validCategoryIds($allowed['preferred_categories']);
        }

        $updated = $this->customers->update($customer->uuid, $allowed);

        // Onboarding completes (status 1) once every required field is filled.
        $missing = $this->missingRequiredFields($updated);
        $newStatus = empty($missing) ? 1 : 0;
        if ((int) $updated->onboarding_status !== $newStatus) {
            $updated = $this->customers->update($updated->uuid, ['onboarding_status' => $newStatus]);
        }

        return [
            'updated' => true,
            'buyer_id' => $updated->buyer_id,
            'onboarding_complete' => empty($missing),
            'missing_fields' => $missing,
        ];
    }

    private function createOrderRequest(array $input, array $context): array
    {
        $customer = $this->customers->findByWhatsappNumber($context['whatsapp_number']);
        if (!$customer) {
            return ['error' => 'Customer not registered. Complete onboarding first.'];
        }

        $missing = $this->missingRequiredFields($customer);
        if (!empty($missing)) {
            return [
                'error' => 'Onboarding incomplete - collect these fields first: ' . implode(', ', $missing),
            ];
        }

        try {
            return $this->orderRequests->createFromChat(
                $customer,
                $input['lines'] ?? [],
                $input['note'] ?? null
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['error' => collect($e->errors())->flatten()->first()];
        }
    }

    private function getMyOrderRequests(array $context): array
    {
        $customer = $this->customers->findByWhatsappNumber($context['whatsapp_number']);
        if (!$customer) {
            return ['error' => 'Customer not registered. Complete onboarding first.'];
        }

        $requests = $this->orderRequests->listForCustomer($customer);

        if (empty($requests)) {
            return ['requests' => [], 'note' => 'This customer has no order requests yet.'];
        }

        return ['requests' => $requests];
    }

    private function cancelOrderRequest(array $input, array $context): array
    {
        $customer = $this->customers->findByWhatsappNumber($context['whatsapp_number']);
        if (!$customer) {
            return ['error' => 'Customer not registered.'];
        }

        if (empty($input['request_no'])) {
            return ['error' => 'request_no is required.'];
        }

        try {
            $request = $this->orderRequests->cancelForCustomer($customer, $input['request_no']);

            return ['cancelled' => true, 'request_no' => $request->request_no];
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['error' => collect($e->errors())->flatten()->first()];
        }
    }

    private function missingRequiredFields($customer): array
    {
        $missing = [];
        if (empty(trim((string) $customer->name)) || str_starts_with((string) $customer->name, 'WhatsApp ')) {
            $missing[] = 'name';
        }
        if (empty(trim((string) $customer->city))) {
            $missing[] = 'city';
        }
        if (empty($customer->preferred_categories)) {
            $missing[] = 'preferred_categories';
        }

        return $missing;
    }

    private function validCategoryIds(array $ids): array
    {
        return Item_category::whereIn('id', $ids)->pluck('id')->all();
    }
}