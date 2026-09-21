<?php

namespace App\Console\Commands;

use App\Services\Backup\GoogleDriveDestination;
use App\Services\OriginalStorage;
use App\Services\Telegram\BotFlow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class DatasetDoctor extends Command
{
    protected $signature = 'dataset:doctor';

    protected $description = 'Validate production runtime and collection configuration';

    public function handle(): int
    {
        $checks = [
            'PHP 8.3+' => PHP_VERSION_ID >= 80300,
            'Production debug disabled' => ! config('app.debug'),
            'Application key present' => filled(config('app.key')),
            'HTTPS application URL' => str_starts_with(config('app.url'), 'https://'),
            'Secure session cookies' => (bool) config('session.secure'),
            'Telegram token configured' => filled(config('telegram.token')),
            'Positive recording target' => config('dataset.target') > 0,
            'Valid duration limits' => config('dataset.min_duration') > 0 && config('dataset.max_duration') >= config('dataset.min_duration'),
            'At least one independent copy enabled' => config('dataset.local_sync') || config('dataset.drive_enabled'),
            'Local sync token is strong' => ! config('dataset.local_sync') || strlen((string) config('dataset.sync_token')) >= 32,
            'At least one permitted age range' => count(app(BotFlow::class)->ageRanges()) > 0,
            'Queue is asynchronous' => config('queue.default') !== 'sync',
            'Original disk uses local filesystem' => config('filesystems.disks.'.config('dataset.disk').'.driver') === 'local',
        ];
        if (config('dataset.drive_enabled')) {
            $checks['Drive credentials configured'] = filled(config('dataset.drive_folder')) && filled(config('dataset.drive_client_id')) && filled(config('dataset.drive_client_secret')) && filled(config('dataset.drive_refresh_token'));
            if ($checks['Drive credentials configured']) {
                $driveError = app(GoogleDriveDestination::class)->authError();
                $checks['Drive login accepted by Google'] = $driveError === null;
                if ($driveError) {
                    $this->warn('Drive: '.$driveError);
                }
            }
        }
        try {
            $checks['PostgreSQL 17+'] = DB::connection()->getDriverName() === 'pgsql' && (int) DB::selectOne('SHOW server_version_num')->server_version_num >= 170000;
            $checks['Database tables migrated'] = Schema::hasTable('telegram_updates');
        } catch (\Throwable) {
            $checks['PostgreSQL 17+'] = false;
        }
        try {
            Cache::put('doctor:probe', 'ok', 10);
            $checks['Shared cache available'] = Cache::get('doctor:probe') === 'ok' && config('cache.default') !== 'array';
            $lock = Cache::lock('doctor:lock', 10);
            $checks['Distributed locks available'] = $lock->get();
            $lock->release();
        } catch (\Throwable) {
            $checks['Shared cache available'] = false;
        }
        try {
            $path = Storage::disk(config('dataset.disk'))->path('');
            $checks['Storage writable with reserve'] = is_writable($path) && disk_free_space($path) >= config('dataset.minimum_free_bytes');
            $checks['Storage is private'] = ! str_starts_with(str_replace('\\', '/', realpath($path) ?: $path), str_replace('\\', '/', public_path()));
        } catch (\Throwable) {
            $checks['Storage writable with reserve'] = false;
        }
        try {
            $probeError = app(OriginalStorage::class)->writeProbe();
            $checks['Original storage accepts new voices (write probe)'] = $probeError === null;
            if ($probeError) {
                $this->warn('Storage: '.$probeError.' - the dataset directory must be writable by the container user (www-data, uid 33).');
            }
        } catch (\Throwable) {
            $checks['Original storage accepts new voices (write probe)'] = false;
        }
        foreach ($checks as $label => $ok) {
            $ok ? $this->info('PASS '.$label) : $this->error('FAIL '.$label);
        }

        return in_array(false, $checks, true) ? 1 : 0;
    }
}
