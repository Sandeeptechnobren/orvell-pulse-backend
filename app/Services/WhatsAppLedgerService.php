<?php

namespace App\Services;

use App\Models\WhatsappMessage;

class WhatsAppLedgerService
{
    public function list(array $filters = [])
    {
        return WhatsappMessage::query()
            ->when($filters['direction'] ?? null, fn ($q, $v) => $q->where('direction', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['wa_id'] ?? null, function ($q, $v) {
                $q->where(fn ($w) => $w->where('sender_wa_id', $v)->orWhere('recipient_wa_id', $v));
            })
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);
    }

    public function conversation(string $waId, int $perPage = 50)
    {
        return WhatsappMessage::query()
            ->where(fn ($q) => $q->where('sender_wa_id', $waId)->orWhere('recipient_wa_id', $waId))
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
