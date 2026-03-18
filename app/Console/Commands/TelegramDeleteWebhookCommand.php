<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

class TelegramDeleteWebhookCommand extends Command
{
    protected $signature = 'telegram:delete-webhook';
    protected $description = 'Delete Telegram bot webhook';

    public function handle(TelegramService $telegram): int
    {
        $result = $telegram->deleteWebhook();

        if ($result['ok'] ?? false) {
            $this->info('Webhook deleted successfully!');
            return self::SUCCESS;
        }

        $this->error('Failed to delete webhook: ' . ($result['description'] ?? 'Unknown error'));
        return self::FAILURE;
    }
}