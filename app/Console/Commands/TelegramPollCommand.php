<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TelegramPollCommand extends Command
{
    protected $signature = 'telegram:poll';
    protected $description = 'Poll Telegram updates (for local development without webhook)';

    public function handle(TelegramService $telegram): int
    {
        $this->info('Starting long polling... Press Ctrl+C to stop.');

        $telegram->deleteWebhook();

        $token = config('telegram.token');
        $apiUrl = "https://api.telegram.org/bot{$token}";
        $offset = 0;

        while (true) {
            $response = Http::timeout(35)->get("{$apiUrl}/getUpdates", [
                'offset' => $offset,
                'timeout' => 30,
            ]);

            $data = $response->json();

            if (!($data['ok'] ?? false)) {
                $this->error('Failed to get updates: ' . ($data['description'] ?? 'Unknown error'));
                sleep(5);
                continue;
            }

            foreach ($data['result'] as $update) {
                $offset = $update['update_id'] + 1;

                $this->info("Processing update #{$update['update_id']}");

                Http::post(rtrim(config('app.url'), '/') . '/api/telegram/webhook', $update);
            }
        }
    }
}