<?php

use App\Jobs\BackupRecording;
use App\Jobs\SendTelegramReply;
use App\Jobs\WorkerHeartbeat;
use App\Models\TelegramOutbox;
use App\Models\VoiceRecording;
use App\Services\ParticipantDeletion;
use App\Services\Telegram\Inbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    Cache::put('heartbeat:scheduler', now()->timestamp, 300);
    WorkerHeartbeat::dispatch();
    WorkerHeartbeat::dispatch('replies')->onQueue('telegram-replies');
    WorkerHeartbeat::dispatch('backups')->onQueue('google-drive');
    app(Inbox::class)->dispatchPending();
    TelegramOutbox::whereNull('sent_at')->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))->orderBy('id')->limit(500)->pluck('id')->each(fn ($id) => SendTelegramReply::dispatch($id));
    if (config('dataset.drive_enabled')) {
        VoiceRecording::whereNull('deletion_requested_at')->where('backup_status', '!=', 'COMPLETED')->where('updated_at', '<', now()->subMinutes(10))->limit(500)->pluck('id')->each(fn ($id) => BackupRecording::dispatch($id));
    }
    if (disk_free_space(storage_path('app/private')) < config('dataset.minimum_free_bytes')) {
        Log::critical('dataset.disk_critical');
    }
})->name('dataset-recovery')->everyMinute()->withoutOverlapping();
Schedule::call(fn () => app(ParticipantDeletion::class)->purge())->name('dataset-privacy-purge')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('dataset:verify')->dailyAt('02:00')->withoutOverlapping();
