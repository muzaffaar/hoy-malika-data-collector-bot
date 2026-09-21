<?php

namespace App\Services\Telegram;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Inbox
{
    public function ingest(array $updates): void
    {
        DB::transaction(function () use ($updates) {
            DB::table('telegram_cursors')->insertOrIgnore(['id' => 1, 'last_ingested_id' => -1, 'last_processed_id' => -1]);
            $cursor = DB::table('telegram_cursors')->where('id', 1)->lockForUpdate()->first();
            $last = (int) ($cursor->last_ingested_id ?? -1);
            foreach ($updates as $update) {
                $id = (int) $update['update_id'];
                $created = DB::table('telegram_updates')->insertOrIgnore([
                    'id' => $id, 'telegram_user_id' => $update['message']['from']['id'] ?? null,
                    'payload' => json_encode($update, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
                ]);
                Log::info($created ? 'telegram.update_received' : 'telegram.duplicate_update', ['update_id' => $id]);
                $last = max($last, $id);
            }
            DB::table('telegram_cursors')->where('id', 1)->update(['last_ingested_id' => $last]);
        });
    }

    public function dispatchPending(): void
    {
        TelegramUpdate::whereNull('processed_at')->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('id')->limit(500)->pluck('id')->each(fn ($id) => ProcessTelegramUpdate::dispatch($id));
    }
}
