<?php

namespace App\Http\Controllers;

use App\Models\VoiceRecording;
use App\Services\OriginalStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DatasetSyncController extends Controller
{
    public function pending()
    {
        abort_unless(config('dataset.local_sync'), 503);

        return VoiceRecording::whereNull('deletion_requested_at')->where('local_sync_status', '!=', 'COMPLETED')->orderBy('created_at')->limit(100)
            ->get(['id', 'relative_storage_path', 'sha256_checksum', 'file_size_bytes']);
    }

    public function download(VoiceRecording $recording, OriginalStorage $storage)
    {
        abort_if($recording->deletion_requested_at, 410);
        abort_unless($storage->valid($recording), 409, 'Source integrity failure');

        return response()->download($storage->path($recording->relative_storage_path), $recording->stored_filename, ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store']);
    }

    public function complete(Request $request, VoiceRecording $recording)
    {
        $data = $request->validate(['sha256' => ['required', 'string', 'size:64'], 'size' => ['required', 'integer', 'min:1']]);
        abort_if($recording->deletion_requested_at, 410);
        abort_unless(hash_equals($recording->sha256_checksum, $data['sha256']) && (int) $data['size'] === (int) $recording->file_size_bytes, 422, 'Checksum or size mismatch');
        $recording->update(['local_sync_status' => 'COMPLETED', 'local_sync_completed_at' => now()]);
        Log::info('local_sync.completed', ['recording_id' => $recording->id]);

        return response()->noContent();
    }

    public function deletions()
    {
        return VoiceRecording::whereNotNull('deletion_requested_at')->whereNull('local_deleted_at')->limit(100)->get(['id', 'relative_storage_path', 'sha256_checksum']);
    }

    public function deleted(VoiceRecording $recording)
    {
        abort_unless($recording->deletion_requested_at, 409);
        $recording->update(['local_deleted_at' => now()]);

        return response()->noContent();
    }
}
