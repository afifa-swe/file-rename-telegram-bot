<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    private string $apiUrl;

    public function __construct()
    {
        $token = config('telegram.token');
        $this->apiUrl = "https://api.telegram.org/bot{$token}";
    }

    public function sendMessage(int $chatId, string $text, array $extra = []): array
    {
        $response = Http::post("{$this->apiUrl}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            ...$extra,
        ]);

        return $response->json();
    }

    public function getFileUrl(string $fileId): ?string
    {
        $response = Http::get("{$this->apiUrl}/getFile", [
            'file_id' => $fileId,
        ]);

        $result = $response->json();

        if (!($result['ok'] ?? false)) {
            Log::error('Telegram getFile failed', $result);
            return null;
        }

        $filePath = $result['result']['file_path'];
        $token = config('telegram.token');

        return "https://api.telegram.org/file/bot{$token}/{$filePath}";
    }

    public function sendDocument(int $chatId, string $fileUrl, string $fileName): array
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'tgbot_');
        $fileContent = Http::get($fileUrl)->body();
        file_put_contents($tempPath, $fileContent);

        $response = Http::attach(
            'document',
            file_get_contents($tempPath),
            $fileName
        )->post("{$this->apiUrl}/sendDocument", [
            'chat_id' => $chatId,
        ]);

        unlink($tempPath);

        return $response->json();
    }

    public function setWebhook(string $url): array
    {
        $params = ['url' => $url];

        $secret = config('telegram.webhook_secret');
        if ($secret) {
            $params['secret_token'] = $secret;
        }

        $response = Http::post("{$this->apiUrl}/setWebhook", $params);

        return $response->json();
    }

    public function deleteWebhook(): array
    {
        $response = Http::post("{$this->apiUrl}/deleteWebhook");

        return $response->json();
    }
}