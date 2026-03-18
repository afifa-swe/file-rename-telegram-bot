<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

class TelegramSetWebhookCommand extends Command
{
    protected $signature = 'telegram:set-webhook {url? : Webhook URL (defaults to APP_URL/api/telegram/webhook)}';
    protected $description = 'Set Telegram bot webhook URL';

    public function handle(TelegramService $telegram): int
    {
        $url = $this->argument('url') ?? rtrim(config('app.url'), '/') . '/api/telegram/webhook';

        $this->info("Setting webhook to: {$url}");

        $result = $telegram->setWebhook($url);

        if ($result['ok'] ?? false) {
            $this->info('Webhook set successfully!');
            return self::SUCCESS;
        }

        $this->error('Failed to set webhook: ' . ($result['description'] ?? 'Unknown error'));
        return self::FAILURE;
    }
}