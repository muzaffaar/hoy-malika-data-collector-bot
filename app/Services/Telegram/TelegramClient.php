<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramClient
{
    public function call(string $method, array $data = []): array
    {
        if (! config('telegram.token')) {
            throw new TelegramApiException(60);
        }
        try {
            $response = Http::connectTimeout(10)->timeout(config('telegram.timeout') + 15)
                ->post('https://api.telegram.org/bot'.config('telegram.token').'/'.$method, $data);
        } catch (\Throwable) {
            Log::warning('telegram.network_failure', ['method' => $method]);
            throw new TelegramApiException;
        }
        if (! $response->successful() || ! $response->json('ok')) {
            Log::warning('telegram.api_failure', ['method' => $method, 'status' => $response->status()]);
            throw new TelegramApiException(max(3, (int) $response->json('parameters.retry_after', 3)), (int) $response->json('error_code', $response->status()));
        }

        return (array) $response->json('result', []);
    }

    public function download(string $fileId, string $temporaryPath): array
    {
        $file = $this->call('getFile', ['file_id' => $fileId]);
        $path = $file['file_path'] ?? '';
        if (! $path || str_contains($path, '..') || ! preg_match('~^[a-zA-Z0-9_./-]+$~', $path)) {
            throw new TelegramApiException;
        }
        $response = null;
        try {
            $response = Http::connectTimeout(10)->timeout(45)->withOptions([
                'sink' => $temporaryPath,
                'progress' => function ($total, $downloaded) {
                    if (max($total, $downloaded) > config('dataset.max_bytes')) {
                        throw new \RuntimeException('File too large');
                    }
                },
            ])->get('https://api.telegram.org/file/bot'.config('telegram.token').'/'.$path);
            if (! $response->successful()) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            throw new TelegramApiException;
        } finally {
            // Guzzle keeps the sink stream open until GC. An open handle makes rename()+chmod() fail
            // with ENOENT on Windows-drive bind mounts (9p/drvfs), so release it deterministically.
            $response?->toPsrResponse()->getBody()->close();
        }

        return $file;
    }
}
