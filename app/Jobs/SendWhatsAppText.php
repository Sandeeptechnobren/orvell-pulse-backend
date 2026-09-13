<?php

namespace App\Jobs;

use App\Services\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendWhatsAppText implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct(
        public string $chatId,
        public string $message,
        public ?string $context = null
    ) {
    }

    public function handle(WhatsAppService $whatsAppService): void
    {
        $whatsAppService->sendText($this->chatId, $this->message);
    }

    public function failed(Throwable $e): void
    {
        Log::error('WhatsApp text job failed permanently', [
            'chat_id' => $this->chatId,
            'context' => $this->context,
            'error' => $e->getMessage(),
        ]);
    }
}
