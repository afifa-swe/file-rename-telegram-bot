<?php

namespace App\Http\Controllers;

use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __construct(
        private TelegramService $telegram,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $update = $request->all();

        Log::debug('Telegram webhook update', $update);

        $message = $update['message'] ?? null;

        if (!$message) {
            return response()->json(['ok' => true]);
        }

        $chatId = $message['chat']['id'];

        if (isset($message['text']) && str_starts_with($message['text'], '/')) {
            return $this->handleCommand($chatId, $message['text']);
        }

        if ($this->hasFile($message)) {
            return $this->handleFileUpload($chatId, $message);
        }

        if (isset($message['text'])) {
            return $this->handleTextMessage($chatId, $message['text']);
        }

        return response()->json(['ok' => true]);
    }

    private function handleCommand(int $chatId, string $command): JsonResponse
    {
        $command = trim(explode(' ', $command)[0]);

        match ($command) {
            '/start' => $this->telegram->sendMessage($chatId, $this->startMessage()),
            '/help' => $this->telegram->sendMessage($chatId, $this->helpMessage()),
            '/cancel' => $this->handleCancel($chatId),
            default => $this->telegram->sendMessage($chatId, "Неизвестная команда. Используйте /help для справки."),
        };

        return response()->json(['ok' => true]);
    }

    private function handleFileUpload(int $chatId, array $message): JsonResponse
    {
        $fileData = $this->extractFileData($message);

        if (!$fileData) {
            $this->telegram->sendMessage($chatId, "Не удалось получить файл. Попробуйте ещё раз.");
            return response()->json(['ok' => true]);
        }

        $caption = trim($message['caption'] ?? '');

        if ($caption !== '' && !str_starts_with($caption, '/')) {
            return $this->renameAndSend($chatId, $fileData, $caption);
        }

        Cache::put("tgbot:file:{$chatId}", $fileData, now()->addMinutes(30));

        $originalName = $fileData['file_name'];
        $this->telegram->sendMessage(
            $chatId,
            "Файл <b>{$originalName}</b> получен.\n\nОтправьте новое имя для файла (с расширением или без)."
        );

        return response()->json(['ok' => true]);
    }

    private function handleTextMessage(int $chatId, string $text): JsonResponse
    {
        $fileData = Cache::get("tgbot:file:{$chatId}");

        if (!$fileData) {
            $this->telegram->sendMessage(
                $chatId,
                "Сначала отправьте мне файл, который нужно переименовать."
            );
            return response()->json(['ok' => true]);
        }

        $newName = trim($text);

        if ($newName === '') {
            $this->telegram->sendMessage($chatId, "Имя файла не может быть пустым. Попробуйте ещё раз.");
            return response()->json(['ok' => true]);
        }

        Cache::forget("tgbot:file:{$chatId}");

        return $this->renameAndSend($chatId, $fileData, $newName);
    }

    private function renameAndSend(int $chatId, array $fileData, string $newName): JsonResponse
    {
        $originalName = $fileData['file_name'];
        $originalExt = pathinfo($originalName, PATHINFO_EXTENSION);
        $newExt = pathinfo($newName, PATHINFO_EXTENSION);

        if ($newExt === '' && $originalExt !== '') {
            $newName .= '.' . $originalExt;
        }

        $fileUrl = $this->telegram->getFileUrl($fileData['file_id']);

        if (!$fileUrl) {
            $this->telegram->sendMessage($chatId, "Не удалось скачать файл. Отправьте файл заново.");
            return response()->json(['ok' => true]);
        }

        $this->telegram->sendDocument($chatId, $fileUrl, $newName);

        $this->telegram->sendMessage(
            $chatId,
            "Готово! Файл переименован: <b>{$originalName}</b> -> <b>{$newName}</b>\n\nМожете отправить следующий файл."
        );

        return response()->json(['ok' => true]);
    }

    private function handleCancel(int $chatId): void
    {
        $fileData = Cache::get("tgbot:file:{$chatId}");

        if ($fileData) {
            Cache::forget("tgbot:file:{$chatId}");
            $this->telegram->sendMessage($chatId, "Операция отменена. Можете отправить новый файл.");
        } else {
            $this->telegram->sendMessage($chatId, "Нечего отменять. Отправьте файл для переименования.");
        }
    }

    private function hasFile(array $message): bool
    {
        return isset($message['document'])
            || isset($message['photo'])
            || isset($message['video'])
            || isset($message['audio'])
            || isset($message['voice'])
            || isset($message['video_note']);
    }

    private function extractFileData(array $message): ?array
    {
        if (isset($message['document'])) {
            return [
                'file_id' => $message['document']['file_id'],
                'file_name' => $message['document']['file_name'] ?? 'document',
            ];
        }

        if (isset($message['photo'])) {
            $photo = end($message['photo']);
            return [
                'file_id' => $photo['file_id'],
                'file_name' => 'photo.jpg',
            ];
        }

        if (isset($message['video'])) {
            return [
                'file_id' => $message['video']['file_id'],
                'file_name' => $message['video']['file_name'] ?? 'video.mp4',
            ];
        }

        if (isset($message['audio'])) {
            return [
                'file_id' => $message['audio']['file_id'],
                'file_name' => $message['audio']['file_name'] ?? 'audio.mp3',
            ];
        }

        if (isset($message['voice'])) {
            return [
                'file_id' => $message['voice']['file_id'],
                'file_name' => 'voice.ogg',
            ];
        }

        if (isset($message['video_note'])) {
            return [
                'file_id' => $message['video_note']['file_id'],
                'file_name' => 'video_note.mp4',
            ];
        }

        return null;
    }

    private function startMessage(): string
    {
        return <<<HTML
        Привет! Я бот для переименования файлов.

        <b>Как пользоваться:</b>
        1. Отправьте мне файл с подписью (новым именем) — переименую сразу
        2. Или отправьте файл без подписи — я спрошу новое имя

        Отправьте файл, чтобы начать!
        HTML;
    }

    private function helpMessage(): string
    {
        return <<<HTML
        <b>Команды:</b>
        /start - Начать работу
        /help - Справка
        /cancel - Отменить текущую операцию

        <b>Поддерживаемые типы:</b>
        - Документы
        - Фото
        - Видео
        - Аудио
        - Голосовые сообщения
        - Видеосообщения

        Просто отправьте файл и новое имя!
        HTML;
    }
}