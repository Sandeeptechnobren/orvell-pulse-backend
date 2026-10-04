<?php

namespace App\Services\Ai;

use App\Models\User;
use Illuminate\Support\Facades\Log;

class StaffToolHandler
{
    /**
     * Staff tool catalog. Intentionally empty for now - the admin line ships
     * gate-first; tools are added once the staff flow design is approved.
     */
    public function definitions(): array
    {
        return [];
    }

    public function execute(string $toolName, array $input, array $context): string
    {
        // Defense in depth: even if a tool is ever called, the sender must be
        // registered staff. The primary gate lives in ProcessAdminConversation.
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

        return json_encode(['error' => "Unknown tool: {$toolName}"]);
    }

    public function resolveStaff(array $context): ?User
    {
        $number = $context['whatsapp_number'] ?? null;

        if (!$number) {
            return null;
        }

        return User::where('whatsapp_number', $number)->first();
    }
}
