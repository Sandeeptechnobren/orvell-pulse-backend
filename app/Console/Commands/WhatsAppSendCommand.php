<?php

namespace App\Console\Commands;

use App\Services\WhatsAppSender;
use Illuminate\Console\Command;

/**
 * Send a WhatsApp message from the command line.
 *
 *   php artisan whatsapp:send 233551992850 "Your pickup code is PKP-4F2A9C"
 *   php artisan whatsapp:send 43835006668981@lid "Hello" --agent=customer
 */
class WhatsAppSendCommand extends Command
{
    protected $signature = 'whatsapp:send
                            {to : Phone number, or a full chat id such as 4383…@lid}
                            {message : The message text}
                            {--agent=customer : Which instance to send through (customer|admin)}
                            {--client= : Restrict to a specific client_id}';

    protected $description = 'Send a WhatsApp message through a registered instance';

    public function handle(WhatsAppSender $sender): int
    {
        $agent = (string) $this->option('agent');

        if (! in_array($agent, ['customer', 'admin'], true)) {
            $this->error("--agent must be 'customer' or 'admin'.");

            return self::FAILURE;
        }

        $result = $sender->send(
            (string) $this->argument('to'),
            (string) $this->argument('message'),
            $agent,
            $this->option('client') ? (int) $this->option('client') : null,
        );

        $this->newLine();
        $this->line('  to        '.$result['to']);
        $this->line('  instance  '.($result['instance'] ?? '—'));
        $this->line('  status    '.$result['status']);

        if ($result['note']) {
            $this->line('  note      '.$result['note']);
        }

        $this->newLine();

        if ($result['delivered']) {
            $this->info('  Delivered.');

            return self::SUCCESS;
        }

        $this->error('  Not delivered.');

        return self::FAILURE;
    }
}
