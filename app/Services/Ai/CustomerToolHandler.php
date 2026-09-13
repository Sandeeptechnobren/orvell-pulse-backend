<?php

namespace App\Services\Ai;

use App\Models\Category;
use App\Services\CustomerManagementService;
use Illuminate\Support\Facades\Log;
use Throwable;

class CustomerToolHandler
{
    public function __construct(
        private readonly CustomerManagementService $customers
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
                'name' => 'register_customer',
                'description' => 'Register the messaging buyer as a new customer AFTER they have confirmed their details. Returns the generated Buyer ID. Only call once per customer — check find_customer first.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Full name of the buyer'],
                        'email' => ['type' => 'string', 'description' => 'Email address, if provided'],
                        'address' => ['type' => 'string', 'description' => 'Street address or market location'],
                        'city' => ['type' => 'string', 'description' => 'City or town, e.g. Accra'],
                        'country' => ['type' => 'string', 'description' => 'Country, default Ghana'],
                        'preferred_categories' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'IDs of bale categories the buyer is interested in, from get_categories',
                        ],
                    ],
                    'required' => ['name'],
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

        return [
            'found' => true,
            'buyer_id' => $customer->buyer_id,
            'name' => $customer->name,
            'email' => $customer->email,
            'city' => $customer->city,
            'country' => $customer->country,
            'preferred_categories' => $customer->preferred_categories,
            'onboarding_status' => $customer->onboarding_status,
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

    private function registerCustomer(array $input, array $context): array
    {
        $existing = $this->customers->findByWhatsappNumber($context['whatsapp_number']);
        if ($existing) {
            return [
                'error' => 'This customer is already registered.',
                'buyer_id' => $existing->buyer_id,
            ];
        }

        $customer = $this->customers->create([
            'name' => $input['name'],
            'whatsapp_number' => $context['whatsapp_number'],
            'wa_id' => $context['wa_id'] ?? null,
            'email' => $input['email'] ?? null,
            'address' => $input['address'] ?? null,
            'city' => $input['city'] ?? null,
            'country' => $input['country'] ?? 'Ghana',
            'preferred_categories' => $this->validCategoryIds($input['preferred_categories'] ?? []),
            'onboarding_status' => 2,
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

        return [
            'updated' => true,
            'buyer_id' => $updated->buyer_id,
        ];
    }

    private function validCategoryIds(array $ids): array
    {
        return Category::whereIn('id', $ids)->pluck('id')->all();
    }
}