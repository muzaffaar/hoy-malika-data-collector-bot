<?php

namespace App\Services\Telegram;

use GuzzleHttp\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramClient
{
    /** One cURL handler per PHP process, so TCP/TLS connections to api.telegram.org are kept alive and reused. */
    private static $handler = null;

    /**
     * A fresh Laravel HTTP client uses a fresh cURL handler, i.e. a brand-new DNS + TCP + TLS handshake for every call
     * (about 70% of a Telegram call's time on a 100 ms route). Sharing the handler lets the queue workers and the poller
     * reuse the open connection instead.
     */
    private function http(): PendingRequest
    {
        self::$handler ??= Utils::chooseHandler();

        return Http::setHandler(self::$handler);
    }

    public function call(string $method, array $data = []): array
    {
        if (! config('telegram.token')) {
            throw new TelegramApiException(60);
        }
        $started = hrtime(true);
        try {
            $response = $this->http()->connectTimeout(10)->timeout($method === 'getUpdates' ? config('telegram.timeout') + 15 : 15)->withOptions($this->networkOptions())
                ->post('https://api.telegram.org/bot'.config('telegram.token').'/'.$method, $data);
        } catch (\Throwable) {
            Log::warning('telegram.network_failure', ['method' => $method]);
            throw new TelegramApiException;
        }
        if ($method !== 'getUpdates') {
            $this->logIfSlow($method, $started);
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
        $started = hrtime(true);
        try {
            $response = $this->http()->connectTimeout(10)->timeout(45)->withOptions($this->networkOptions() + [
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
            $this->logIfSlow('fileDownload', $started);
        }

        return $file;
    }

    /** Guzzle/curl options shared by every Telegram request. */
    private function networkOptions(): array
    {
        return config('telegram.force_ipv4') ? ['force_ip_resolve' => 'v4'] : [];
    }

    /** Anything slower than 1.5 s shows up in the logs with its stage, so slowness can be attributed. */
    private function logIfSlow(string $what, int $startedNs): void
    {
        $ms = (int) ((hrtime(true) - $startedNs) / 1e6);
        if ($ms > 1500) {
            Log::warning('telegram.slow_call', ['call' => $what, 'ms' => $ms]);
        }
    }
}
