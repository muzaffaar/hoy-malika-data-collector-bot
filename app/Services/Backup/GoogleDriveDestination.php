<?php

namespace App\Services\Backup;

use App\Contracts\BackupDestination;
use App\Models\VoiceRecording;
use App\Services\OriginalStorage;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleDriveDestination implements BackupDestination
{
    private function refresh(): Response
    {
        return Http::connectTimeout(10)->timeout(20)->asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('dataset.drive_client_id'), 'client_secret' => config('dataset.drive_client_secret'),
            'refresh_token' => config('dataset.drive_refresh_token'), 'grant_type' => 'refresh_token',
        ]);
    }

    /** Live credential check for dataset:doctor: null when Google accepts the refresh token, otherwise Google's reason. */
    public function authError(): ?string
    {
        try {
            $r = $this->refresh();
        } catch (\Throwable) {
            return 'Google OAuth endpoint unreachable';
        }

        return $r->successful() && $r->json('access_token') ? null : trim(($r->json('error') ?? 'HTTP '.$r->status()).': '.$r->json('error_description'), ': ');
    }

    private function token(): string
    {
        return Cache::remember('drive:access-token', 3000, function () {
            try {
                $r = $this->refresh();
                if (! $r->successful() || ! $r->json('access_token')) {
                    // Google's error code/description (e.g. unauthorized_client, invalid_grant) never contain secrets.
                    Log::error('drive.auth_failed', ['status' => $r->status(), 'error' => $r->json('error'), 'description' => $r->json('error_description')]);
                    throw new \RuntimeException;
                }

                return $r->json('access_token');
            } catch (\Throwable) {
                throw new \RuntimeException('Drive authentication failed');
            }
        });
    }

    private function api(string $method, string $url, array $options = []): array
    {
        try {
            $r = Http::withToken($this->token())->connectTimeout(10)->timeout(45)->send($method, $url, $options);
            if ($r->status() === 401) {
                Cache::forget('drive:access-token');
            }
            if (! $r->successful()) {
                Log::warning('drive.api_failed', ['method' => $method, 'status' => $r->status(), 'reason' => $r->json('error.errors.0.reason') ?? $r->json('error.status'), 'message' => $r->json('error.message')]);
                throw new \RuntimeException;
            }

            return $r->json() ?? [];
        } catch (\Throwable) {
            throw new \RuntimeException('Drive API request failed');
        }
    }

    private function folder(string $parent, string $name): string
    {
        if (! preg_match('/^[\w-]+$/', $parent) || ! preg_match('/^[\w-]+$/', $name)) {
            throw new \RuntimeException('Invalid Drive folder');
        }
        $lock = Cache::lock('drive:folder:'.$parent.':'.$name, 100);

        return $lock->block(10, function () use ($parent, $name) {
            $url = 'https://www.googleapis.com/drive/v3/files';
            $found = $this->api('GET', $url, ['query' => ['q' => "'{$parent}' in parents and name = '{$name}' and mimeType = 'application/vnd.google-apps.folder' and trashed = false", 'fields' => 'files(id)', 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true']]);

            return $found['files'][0]['id'] ?? $this->api('POST', $url.'?supportsAllDrives=true', ['json' => ['name' => $name, 'parents' => [$parent], 'mimeType' => 'application/vnd.google-apps.folder']])['id'];
        });
    }

    public function backup(VoiceRecording $recording): string
    {
        if (! app(OriginalStorage::class)->valid($recording)) {
            throw new \RuntimeException('Source integrity failure');
        }
        $url = 'https://www.googleapis.com/drive/v3/files';
        $remoteId = $recording->google_drive_file_id;
        if (! $remoteId) {
            // Preallocate and persist the remote ID before uploading: retries cannot create a second copy.
            $remoteId = $this->api('GET', $url.'/generateIds', ['query' => ['count' => 1, 'space' => 'drive', 'type' => 'files']])['ids'][0];
            $recording->update(['google_drive_file_id' => $remoteId]);
        }
        try {
            $metadata = $this->api('GET', $url.'/'.$remoteId, ['query' => ['fields' => 'id,size,sha256Checksum,trashed', 'supportsAllDrives' => 'true']]);
        } catch (\RuntimeException) {
            $metadata = null;
        }
        if (! $metadata) {
            $parent = config('dataset.drive_folder');
            if (! $parent) {
                throw new \RuntimeException('Drive root folder not configured');
            }
            foreach (['original', ...explode('/', $recording->telegram_received_at->format('Y/m/d'))] as $name) {
                $parent = $this->folder($parent, $name);
            }
            $boundary = 'dataset_'.bin2hex(random_bytes(16));
            $meta = json_encode(['id' => $remoteId, 'name' => $recording->stored_filename, 'parents' => [$parent]], JSON_THROW_ON_ERROR);
            $bytes = file_get_contents(app(OriginalStorage::class)->path($recording->relative_storage_path));
            $body = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n{$meta}\r\n--{$boundary}\r\nContent-Type: application/octet-stream\r\n\r\n".$bytes."\r\n--{$boundary}--\r\n";
            $this->api('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true', ['headers' => ['Content-Type' => 'multipart/related; boundary='.$boundary], 'body' => $body]);
            $metadata = $this->api('GET', $url.'/'.$remoteId, ['query' => ['fields' => 'id,size,sha256Checksum,trashed', 'supportsAllDrives' => 'true']]);
        }
        if (($metadata['trashed'] ?? false) || (int) ($metadata['size'] ?? -1) !== (int) $recording->file_size_bytes || ! hash_equals($recording->sha256_checksum, $metadata['sha256Checksum'] ?? '')) {
            throw new \RuntimeException('Drive checksum/size mismatch');
        }

        return $remoteId;
    }

    public function delete(string $remoteId): void
    {
        try {
            $r = Http::withToken($this->token())->connectTimeout(10)->timeout(30)->delete('https://www.googleapis.com/drive/v3/files/'.rawurlencode($remoteId).'?supportsAllDrives=true');
            if (! $r->successful() && $r->status() !== 404) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            throw new \RuntimeException('Drive deletion failed');
        }
    }
}
