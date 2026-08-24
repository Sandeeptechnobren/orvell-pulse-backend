<?php

namespace App\Services;

use App\Services\WhatsApp\WhatsAppRouterService;

/**
 * WhatsApp Automation Engine for ORVELL PULSE.
 * Acts as the high-level façade over the modular WhatsAppRouterService pipeline.
 */
class WhatsAppAgentService
{
    protected WhatsAppRouterService $router;

    public function __construct(WhatsAppRouterService $router)
    {
        $this->router = $router;
    }

    /**
     * Central message entry point.
     *
     * @param string $agentType ('admin', 'customer', 'buyer', 'staff', 'cashier')
     * @param string $from
     * @param string $message
     * @param int|null $companyId
     * @param int|null $actorUserId
     * @return array ['action' => ..., 'reply' => ...]
     */
    public function handle(string $agentType, string $from, string $message, ?int $companyId = null, ?int $actorUserId = null): array
    {
        return $this->router->route($agentType, $from, $message, $companyId, $actorUserId);
    }
}
   