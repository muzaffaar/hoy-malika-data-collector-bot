<?php

namespace App\Services\Telegram;

use App\Enums\OnboardingState as State;
use App\Models\Participant;
use App\Models\TelegramOutbox;
use App\Models\TelegramUpdate;
use App\Models\VoiceRecording;
use App\Services\OriginalStorage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BotFlow
{
    public function process(TelegramUpdate $update): void
    {
        $m = $update->payload['message'] ?? null;
        if (! $m || ($m['chat']['type'] ?? '') !== 'private' || ($m['from']['is_bot'] ?? true)) {
            $this->finish($update);

            return;
        }
        $p = Participant::firstOrCreate(['telegram_user_id' => $m['from']['id']], [
            'first_name' => mb_substr($m['from']['first_name'] ?? '', 0, 255),
            'last_name' => isset($m['from']['last_name']) ? mb_substr($m['from']['last_name'], 0, 255) : null,
            'telegram_username' => $m['from']['username'] ?? null,
            'telegram_language_code' => $m['from']['language_code'] ?? null,
            'first_interaction_at' => now(), 'last_interaction_at' => now(),
        ]);
        if ($p->wasRecentlyCreated) {
            Log::info('participant.registered', ['participant_id' => $p->id]);
        }
        $p->first_name = mb_substr($m['from']['first_name'] ?? $p->first_name ?? '', 0, 255);
        $p->last_name = isset($m['from']['last_name']) ? mb_substr($m['from']['last_name'], 0, 255) : $p->last_name;
        $p->telegram_username = $m['from']['username'] ?? null;
        $p->telegram_language_code = $m['from']['language_code'] ?? $p->telegram_language_code;
        if ($p->deletion_requested_at) {
            $this->finish($update);

            return;
        }
        $p->last_interaction_at = now();
        $p->is_blocked = false;
        $text = trim($m['text'] ?? '');
        $response = null;
        $voice = $m['voice'] ?? (config('dataset.accept_audio') ? ($m['audio'] ?? null) : null);
        $recording = null;
        if ($text === '/status') {
            $response = $this->reply("Siz {$p->recording_count} ta ovoz yuborgansiz.");
        } elseif (in_array($text, ['/start', '/help'])) {
            $response = $this->prompt($p);
        } elseif ($p->onboarding_state === State::AWAITING_CONSENT || $p->onboarding_state === State::NEW) {
            if ($text === '✅ Roziman') {
                $p->consent_given = true;
                $p->consent_at = now();
                $p->consent_version = config('dataset.consent_version');
                $p->onboarding_state = State::AWAITING_AGE;
                Log::info('participant.consent', ['participant_id' => $p->id, 'version' => $p->consent_version]);
                $response = $this->prompt($p);
            } elseif ($text === '❌ Rozimasman') {
                $response = $this->reply('Tushunarli. Ishtirok etish ixtiyoriy. Fikringiz o‘zgarsa, /start ni bosing.');
            } else {
                $response = $this->prompt($p);
            }
        } elseif ($p->onboarding_state === State::AWAITING_AGE) {
            $ranges = $this->ageRanges();
            if (array_key_exists($text, $ranges)) {
                $p->age_range = $text;
                $p->onboarding_state = State::AWAITING_GENDER;
            }
            $response = $this->prompt($p);
        } elseif ($p->onboarding_state === State::AWAITING_GENDER) {
            if (in_array($text, ['👨 Erkak', '👩 Ayol'])) {
                $p->gender = $text === '👨 Erkak' ? 'MALE' : 'FEMALE';
                $p->onboarding_state = State::READY_FOR_RECORDINGS;
            }
            $response = $this->prompt($p);
        } elseif (isset($m['forward_origin']) || ! $voice) {
            $response = $this->reply('🎙 Iltimos, “Hoy, Malika” deb aytilgan o‘zingizning ovozli xabaringizni yuboring.');
        } elseif (empty($voice['file_id']) || empty($voice['file_unique_id']) || ! isset($voice['duration']) || $voice['duration'] < config('dataset.min_duration')) {
            $response = $this->reply('Ovoz juda qisqa. Iltimos, “Hoy, Malika” iborasini to‘liq ayting.');
        } elseif ($voice['duration'] > config('dataset.max_duration') || ($voice['file_size'] ?? 0) > config('dataset.max_bytes')) {
            $response = $this->reply('Bu ovozli xabar biroz uzun. Iltimos, faqat “Hoy, Malika” iborasini ayting.');
        } elseif ($p->consent_given && $p->gender && $p->age_range) {
            $existing = VoiceRecording::where('telegram_chat_id', $m['chat']['id'])->where('telegram_message_id', $m['message_id'])->first();
            if (! $existing) {
                $stored = app(OriginalStorage::class)->save($p, $m, $voice);
                $recording = $stored + ['participant_id' => $p->id, 'telegram_message_id' => $m['message_id'], 'telegram_chat_id' => $m['chat']['id'],
                    'telegram_file_id' => $voice['file_id'], 'telegram_file_unique_id' => $voice['file_unique_id'], 'duration_seconds' => $voice['duration'],
                    'telegram_received_at' => CarbonImmutable::createFromTimestampUTC($m['date']),
                    'backup_status' => config('dataset.drive_enabled') ? 'PENDING' : 'DISABLED', 'backup_destination' => config('dataset.drive_enabled') ? 'google-drive' : null,
                    'local_sync_status' => config('dataset.local_sync') ? 'PENDING' : 'DISABLED'];
                $p->recording_count++;
                $p->onboarding_state = $p->recording_count >= config('dataset.target') ? State::COMPLETED : State::COLLECTING;
            }
            $response = $this->reply("✅ Ovozli xabaringiz qabul qilindi.\nJami: {$p->recording_count} / ".config('dataset.target'));
            if ($recording && $p->recording_count === config('dataset.target')) {
                $response['text'] .= "\n🎉 Rahmat! Kerakli ovozlar sonini yubordingiz. Xohlasangiz, qo‘shimcha ovozli namunalar ham yuborishingiz mumkin.";
            }
        }
        DB::transaction(function () use ($p, $recording, $update, $m, $response) {
            $p->save();
            if ($recording) {
                VoiceRecording::create($recording);
            }
            if ($response) {
                TelegramOutbox::firstOrCreate(['update_id' => $update->id, 'kind' => 'reply'], ['chat_id' => $m['chat']['id'], 'payload' => $response]);
            }
            $this->finish($update);
        });
    }

    private function finish(TelegramUpdate $update): void
    {
        $update->update(['processed_at' => now(), 'payload' => null, 'last_error' => null]);
        // This watermark is the highest completed ID; individual inbox rows are authoritative.
        DB::table('telegram_cursors')->where('id', 1)->where('last_processed_id', '<', $update->id)->update(['last_processed_id' => $update->id]);
    }

    private function reply(string $text, array $buttons = []): array
    {
        return ['text' => $text, 'reply_markup' => $buttons ? ['keyboard' => array_chunk($buttons, 2), 'resize_keyboard' => true, 'one_time_keyboard' => true] : ['remove_keyboard' => true]];
    }

    public function ageRanges(): array
    {
        return array_filter(config('dataset.age_ranges'), fn ($minimum) => $minimum >= config('dataset.min_age'));
    }

    private function prompt(Participant $p): array
    {
        return match ($p->onboarding_state) {
            State::NEW, State::AWAITING_CONSENT => $this->reply('Assalomu alaykum! 👋 Biz “Hoy, Malika” ovozli yordamchisi uchun ovoz namunalarini yig‘moqdamiz. Ovozlaringiz AI modelini o‘qitish va tadqiqot uchun ishlatiladi. Ishtirok etishga rozimisiz?', ['✅ Roziman', '❌ Rozimasman']),
            State::AWAITING_AGE => $this->reply('Ishtirokchilar kamida '.config('dataset.min_age').' yoshda bo‘lishi kerak. Yoshingiz qaysi oraliqda?', array_keys($this->ageRanges())),
            State::AWAITING_GENDER => $this->reply('Jinsingizni tanlang:', ['👨 Erkak', '👩 Ayol']),
            default => $this->reply("Xush kelibsiz! Siz {$p->recording_count} ta ovoz yuborgansiz.\n🎙 Faqat “Hoy, Malika” deb tabiiy ovozingizda ayting va ovozli xabar yuboring. Har safar bitta ovoz yuboring.\nSekinroq, tezroq yoki biroz uzoqroqdan aytishingiz mumkin."),
        };
    }
}
