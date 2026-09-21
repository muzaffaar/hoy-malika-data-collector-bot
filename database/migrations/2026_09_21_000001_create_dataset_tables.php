<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participants', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('telegram_user_id')->unique();
            $t->string('telegram_username')->nullable();
            $t->string('first_name')->nullable();
            $t->string('gender', 10)->nullable()->index();
            $t->string('age_range', 20)->nullable()->index();
            $t->string('onboarding_state')->default('AWAITING_CONSENT');
            $t->boolean('consent_given')->default(false);
            $t->timestampTz('consent_at')->nullable();
            $t->string('consent_version')->nullable();
            $t->timestampTz('first_interaction_at');
            $t->timestampTz('last_interaction_at');
            $t->unsignedInteger('recording_count')->default(0)->index();
            $t->string('telegram_language_code', 20)->nullable();
            $t->boolean('is_blocked')->default(false);
            $t->timestampTz('deletion_requested_at')->nullable();
            $t->timestampsTz();
            $t->index('created_at');
        });
        Schema::create('telegram_cursors', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->bigInteger('last_ingested_id')->default(-1);
            $t->bigInteger('last_processed_id')->default(-1);
        });
        Schema::create('telegram_updates', function (Blueprint $t) {
            $t->bigInteger('id')->primary();
            $t->bigInteger('telegram_user_id')->nullable()->index();
            $t->json('payload')->nullable();
            $t->timestampTz('processed_at')->nullable()->index();
            $t->unsignedInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->timestampTz('available_at')->nullable()->index();
            $t->timestampsTz();
        });
        Schema::create('voice_recordings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('participant_id')->constrained()->restrictOnDelete();
            $t->bigInteger('telegram_message_id');
            $t->bigInteger('telegram_chat_id');
            $t->string('telegram_file_id');
            $t->string('telegram_file_unique_id')->index();
            $t->string('original_filename')->nullable();
            $t->string('stored_filename');
            $t->string('relative_storage_path')->unique();
            $t->string('mime_type');
            $t->string('original_extension', 16);
            $t->decimal('duration_seconds', 8, 2);
            $t->unsignedBigInteger('file_size_bytes');
            $t->string('sha256_checksum', 64);
            $t->timestampTz('telegram_received_at')->index();
            $t->timestampTz('downloaded_at');
            $t->string('backup_status')->default('PENDING')->index();
            $t->string('backup_destination')->nullable();
            $t->string('google_drive_file_id')->nullable();
            $t->timestampTz('backup_completed_at')->nullable();
            $t->string('backup_error')->nullable();
            $t->string('local_sync_status')->default('PENDING')->index();
            $t->timestampTz('local_sync_completed_at')->nullable();
            $t->string('validation_status')->default('VALID');
            $t->string('validation_failure_reason')->nullable();
            $t->timestampTz('deletion_requested_at')->nullable()->index();
            $t->timestampTz('local_deleted_at')->nullable();
            $t->timestampsTz();
            $t->unique(['telegram_chat_id', 'telegram_message_id']);
            $t->index('created_at');
        });
        Schema::create('telegram_outbox', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('update_id');
            $t->string('kind')->default('reply');
            $t->unique(['update_id', 'kind']);
            $t->bigInteger('chat_id');
            $t->json('payload');
            $t->timestampTz('sent_at')->nullable()->index();
            $t->timestampTz('available_at')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestampsTz();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('admin_id')->nullable();
            $t->string('action');
            $t->string('subject_id');
            $t->json('context');
            $t->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        foreach (['audit_events', 'telegram_outbox', 'voice_recordings', 'telegram_updates', 'telegram_cursors', 'participants'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
