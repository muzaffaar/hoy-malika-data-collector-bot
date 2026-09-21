<?php

namespace App\Services;

use App\Models\Participant;
use App\Models\VoiceRecording;
use App\Services\Telegram\TelegramClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OriginalStorage
{
    public function path(string $relative): string
    {
        return Storage::disk(config('dataset.disk'))->path($relative);
    }

    public function save(Participant $participant, array $message, array $voice): array
    {
        $date = CarbonImmutable::createFromTimestampUTC($message['date']);
        $extension = isset($message['voice']) ? 'ogg' : strtolower(pathinfo($voice['file_name'] ?? '', PATHINFO_EXTENSION));
        if (! in_array($extension, ['ogg', 'oga', 'mp3', 'm4a', 'wav', 'flac', 'aac', 'opus'])) {
            $extension = 'bin';
        }
        $name = sprintf('participant_%08d_message_%d.%s', $participant->id, $message['message_id'], $extension);
        $relative = config('dataset.base_path').'/'.$date->format('Y/m/d').'/'.$name;
        $final = $this->path($relative);
        $dir = dirname($final);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Cannot create dataset directory');
        }
        if (disk_free_space($dir) < config('dataset.minimum_free_bytes')) {
            Log::critical('dataset.disk_critical');
            throw new \RuntimeException('Insufficient disk space; update retained for retry');
        }
        $temporary = $final.'.'.bin2hex(random_bytes(8)).'.part';
        try {
            Log::info('audio.download_started', ['participant_id' => $participant->id, 'message_id' => $message['message_id']]);
            $file = app(TelegramClient::class)->download($voice['file_id'], $temporary);
            $deliveredExtension = strtolower(pathinfo($file['file_path'] ?? '', PATHINFO_EXTENSION));
            if (in_array($deliveredExtension, ['ogg', 'oga', 'mp3', 'm4a', 'wav', 'flac', 'aac', 'opus'])) {
                $extension = $deliveredExtension;
                $name = sprintf('participant_%08d_message_%d.%s', $participant->id, $message['message_id'], $extension);
                $relative = config('dataset.base_path').'/'.$date->format('Y/m/d').'/'.$name;
                $final = $this->path($relative);
            }
            clearstatcache(true, $temporary);
            $size = filesize($temporary);
            if (! $size || $size > config('dataset.max_bytes') || (isset($file['file_size']) && $size !== (int) $file['file_size']) || (isset($voice['file_size']) && $size !== (int) $voice['file_size'])) {
                throw new \RuntimeException('Invalid downloaded size');
            }
            $checksum = hash_file('sha256', $temporary);
            if (! $checksum) {
                throw new \RuntimeException('Checksum failed');
            }
            $handle = fopen($temporary, 'r+b');
            if (! $handle) {
                throw new \RuntimeException('File flush failed');
            }
            try {
                if (! fsync($handle)) {
                    throw new \RuntimeException('File flush failed');
                }
            } finally {
                fclose($handle);
            }
            // Existing crash-recovery files must match. Never overwrite an original.
            if (is_file($final)) {
                if (! hash_equals($checksum, hash_file('sha256', $final)) || filesize($final) !== $size) {
                    throw new \RuntimeException('Original integrity conflict');
                }
            } else {
                if (! rename($temporary, $final)) {
                    throw new \RuntimeException('Atomic move failed');
                }
                // Read-only is hardening only: the bytes are already fsynced and atomically renamed, so a
                // filesystem that rejects chmod (e.g. Windows bind mounts) must not fail and re-queue the save.
                if (! @chmod($final, 0400)) {
                    Log::warning('audio.chmod_failed', ['participant_id' => $participant->id, 'message_id' => $message['message_id']]);
                }
            }
            // Persist directory entries as well as file bytes on the Linux production filesystem.
            if (PHP_OS_FAMILY !== 'Windows') {
                $root = $this->path('');
                for ($directory = $dir; strlen($directory) >= strlen(rtrim($root, '/')); $directory = dirname($directory)) {
                    $directoryHandle = fopen($directory, 'r');
                    if (! $directoryHandle) {
                        throw new \RuntimeException('Cannot open dataset directory');
                    }
                    try {
                        if (! fsync($directoryHandle)) {
                            throw new \RuntimeException('Directory flush failed');
                        }
                    } finally {
                        fclose($directoryHandle);
                    }
                    if ($directory === dirname($directory)) {
                        break;
                    }
                }
            }
            Log::info('audio.saved', ['participant_id' => $participant->id, 'sha256' => $checksum, 'bytes' => $size]);

            return ['stored_filename' => $name, 'relative_storage_path' => $relative, 'original_extension' => $extension,
                'original_filename' => isset($voice['file_name']) ? mb_substr(basename($voice['file_name']), 0, 255) : null,
                'mime_type' => (new \finfo(FILEINFO_MIME_TYPE))->file($final) ?: 'application/octet-stream',
                'file_size_bytes' => $size, 'sha256_checksum' => $checksum, 'downloaded_at' => now()];
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function valid(VoiceRecording $recording): bool
    {
        $path = $this->path($recording->relative_storage_path);

        return is_file($path) && filesize($path) === (int) $recording->file_size_bytes && hash_equals($recording->sha256_checksum, hash_file('sha256', $path));
    }
}
