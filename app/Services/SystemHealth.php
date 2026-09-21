<?php

namespace App\Services;

use App\Models\TelegramUpdate;
use App\Models\VoiceRecording;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SystemHealth
{
    public function report(): array
    {
        $checks = [];
        try {
            DB::select('select 1');
            $checks['Database'] = 'Healthy';
        } catch (\Throwable) {
            $checks['Database'] = 'Unavailable';
        }
        try {
            Cache::put('health:probe', 1, 10);
            $checks['Cache / Redis'] = (int) Cache::get('health:probe') === 1 ? 'Healthy' : 'Unavailable';
            foreach (['poll', 'telegram', 'queue', 'replies', 'backups', 'scheduler'] as $name) {
                $checks[ucfirst($name)] = (int) Cache::get('heartbeat:'.$name, 0) > time() - 180 ? 'Healthy' : 'Stale / not running';
            }
        } catch (\Throwable) {
            $checks['Cache / Redis'] = 'Unavailable';
        }
        $root = storage_path('app/private');
        $free = disk_free_space($root);
        $checks['Private storage'] = is_writable($root) ? 'Writable' : 'Not writable';
        $probeError = app(OriginalStorage::class)->writeProbe();
        $checks['Original storage (voice saves)'] = $probeError === null ? 'Accepting new voices' : 'FAILING: '.$probeError;
        $checks['Free disk'] = number_format($free / 1073741824, 2).' GB'.($free < config('dataset.minimum_free_bytes') ? ' — CRITICAL' : '');
        $checks['Unprocessed updates'] = TelegramUpdate::whereNull('processed_at')->count();
        $checks['Failed / retrying updates'] = TelegramUpdate::whereNull('processed_at')->where('attempts', '>', 0)->count();
        $checks['Drive failed'] = VoiceRecording::where('backup_status', 'FAILED')->count();
        $checks['Oldest pending local copy (UTC)'] = VoiceRecording::whereNotIn('local_sync_status', ['COMPLETED', 'DISABLED'])->min('created_at') ?? 'None';
        $checks['Deletion requests pending'] = VoiceRecording::whereNotNull('deletion_requested_at')->count();

        return $checks;
    }
}
