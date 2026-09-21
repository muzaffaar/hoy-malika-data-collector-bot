<?php

namespace App\Models;

use App\Enums\BackupStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceRecording extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['backup_status' => BackupStatus::class, 'local_sync_status' => BackupStatus::class, 'telegram_received_at' => 'datetime', 'downloaded_at' => 'datetime', 'backup_completed_at' => 'datetime', 'local_sync_completed_at' => 'datetime', 'deletion_requested_at' => 'datetime', 'local_deleted_at' => 'datetime'];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }
}
