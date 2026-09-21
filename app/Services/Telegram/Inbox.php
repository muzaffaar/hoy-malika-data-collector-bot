<?php

namespace App\Services\Telegram;

use App\Jobs\ProcessTelegramUpdate;
use App\Models\TelegramUpdate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Inbox
{
    /** Stores updates durably and returns the ids that were new (duplicates are ignored). */
    public function ingest(array $updates): array
    {
        $new = [];
        DB::transaction(function () use ($updates, &$new) {
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
                if ($created) {
                    $new[] = $id;
                }
                $last = max($last, $id);
            }
            DB::table('telegram_cursors')->where('id', 1)->update(['last_ingested_id' => $last]);
        });

        return $new;
    }

    /**
     * Queue freshly ingested updates. Call after ingest() has committed.
     *
     * Only the oldest unprocessed update of each participant is queued: a participant's messages are handled strictly in
     * order, and each finished update queues its successor. Queueing every update would create jobs that only wait for
     * their predecessor's lock, burning worker time and retry attempts under load.
     */
    public function dispatchNew(array $ids): void
    {
        if ($ids !== []) {
            $this->dispatchHeads(TelegramUpdate::query()->whereIn('id', $ids));
        }
    }

    /** Recovery sweep (scheduler, poller start): re-queue the oldest unprocessed update of every participant that is due. */
    public function dispatchPending(): void
    {
        $this->dispatchHeads(TelegramUpdate::query());
    }

    private function dispatchHeads(Builder $scope): void
    {
        $scope->whereNull('processed_at')
            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('telegram_user_id')->orWhereNotExists(fn ($earlier) => $earlier->selectRaw('1')->from('telegram_updates as earlier')
                ->whereColumn('earlier.telegram_user_id', 'telegram_updates.telegram_user_id')->whereNull('earlier.processed_at')->whereColumn('earlier.id', '<', 'telegram_updates.id')))
            ->orderBy('id')->limit(500)->pluck('id')->each(fn ($id) => ProcessTelegramUpdate::dispatch($id));
    }
}
