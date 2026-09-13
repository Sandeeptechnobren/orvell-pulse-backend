<?php

namespace App\Services\WhatsApp;

use App\Models\Company;
use App\Models\WhatsappConversation;
use App\Services\WhatsApp\Handlers\BuyerHandler;
use App\Services\WhatsApp\Handlers\StaffHandler;
use App\Services\WhatsApp\Handlers\CashierHandler;
use App\Services\WhatsApp\Handlers\AdminHandler;
use App\Services\AuditService;
use Illuminate\Support\Facades\Log;

/**
 * Modular WhatsApp Router Service for ORVELL PULSE.
 *
 * Routes inbound WhatsApp messages to dedicated single-responsibility role handlers:
 *   - BuyerHandler: Buyer onboarding, catalog, order placement, Paystack payment links, tracking, escalation
 *   - StaffHandler: Warehouse pickup releases, operational expense logging, staff sales
 *   - CashierHandler: Cash receipts, bank deposits, daily till summaries
 *   - AdminHandler: Warehouse inventory reports, EOD reconciliation, invoice amendment approvals
 */
class WhatsAppRouterService
{
    protected BuyerHandler $buyerHandler;
    protected StaffHandler $staffHandler;
    protected CashierHandler $cashierHandler;
    protected AdminHandler $adminHandler;
    protected AuditService $auditService;

    public function __construct(
        BuyerHandler $buyerHandler,
        StaffHandler $staffHandler,
        CashierHandler $cashierHandler,
        AdminHandler $adminHandler,
        AuditService $auditService
    ) {
        $this->buyerHandler = $buyerHandler;
        $this->staffHandler = $staffHandler;
        $this->cashierHandler = $cashierHandler;
        $this->adminHandler = $adminHandler;
        $this->auditService = $auditService;
    }
 
    /**
     * Route inbound message to appropriate role handler.
     *
     * @param string $role ('buyer', 'customer', 'staff', 'cashier', 'admin')
     * @param string $from
     * @param string $message
     * @param int|null $companyId
     * @param int|null $actorUserId
     * @return array
     */
    public function route(string $role, string $from, string $message, ?int $companyId = null, ?int $actorUserId = null): array
    {
        $role = mb_strtolower(trim($role));
        $from = trim($from);
        $message = trim($message);

        $companyId = $companyId ?? Company::first()?->id ?? 1;

        // Inbound is recorded by the webhook service and outbound by WhatsAppSender,
        // which is the only place that knows whether the send succeeded.

        // 2. Resolve or initialize persistent conversation state
        $normalizedRole = in_array($role, ['admin', 'cashier', 'staff'], true) ? $role : 'buyer';
        $conversation = $this->getOrCreateConversation($from, $normalizedRole);

        // 3. Delegate to Dedicated Role Handler
        switch ($normalizedRole) {
            case 'admin':
                $result = $this->adminHandler->handle($from, $message, $conversation, $actorUserId, $companyId);
                break;
            case 'cashier':
                $result = $this->cashierHandler->handle($from, $message, $conversation, $actorUserId);
                break;
            case 'staff':
                $result = $this->staffHandler->handle($from, $message, $conversation, $actorUserId);
                break;
            case 'buyer':
            default:
                $result = $this->buyerHandler->handle($from, $message, $conversation);
                break;
        }

        // 5. Update last interaction timestamp
        $conversation->update(['last_interaction_at' => now()]);

        return $result;
    }

    private function getOrCreateConversation(string $waId, string $role): WhatsappConversation
    {
        return WhatsappConversation::firstOrCreate(
            ['wa_id' => $waId],
            [
                'role'         => $role,
                'current_flow' => 'idle',
                'current_step' => 1,
            ]
        );
    }

}
