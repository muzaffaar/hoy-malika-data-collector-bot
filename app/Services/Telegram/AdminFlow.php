<?php

namespace App\Services\Telegram;

use App\Jobs\SendBroadcastMessage;
use App\Models\Participant;
use Closure;
use Illuminate\Support\Facades\Cache;

class AdminFlow
{
    private const MENU_STATS = '📊 Statistika';

    private const MENU_REMINDERS = '📢 Eslatmalar';

    private const USE_DEFAULT = '📨 Standart xabar';

    private const CANCEL = '❌ Bekor qilish';

    /** Button label => internal segment key. */
    private const SEGMENTS = [
        '🎙 Hoy, Malika kam yuborganlar' => 'wake_word',
        '🔀 O‘xshash so‘z yubormaganlar' => 'hard_negative',
        '📣 Ikkalasi ham (birortasi kam bo‘lganlar)' => 'either',
    ];

    public function handle(array $message, int $adminId): array
    {
        $text = trim($message['text'] ?? '');
        if ($text === self::MENU_STATS) {
            return $this->stats();
        }
        if ($text === self::MENU_REMINDERS) {
            return $this->remindersMenu();
        }
        if (isset(self::SEGMENTS[$text])) {
            return $this->askForContent($adminId, self::SEGMENTS[$text]);
        }
        if ($text === self::CANCEL) {
            Cache::forget($this->cacheKey($adminId));

            return $this->remindersMenu();
        }
        $segment = $this->pendingSegment($adminId);
        if ($text === self::USE_DEFAULT && $segment) {
            return $this->broadcast($adminId, $segment, fn (Participant $p) => ['sendMessage', ['text' => $this->defaultMessage($segment, $p), 'reply_markup' => ['remove_keyboard' => true]]]);
        }
        if ($segment && ($videoNote = $message['video_note'] ?? null)) {
            return $this->broadcast($adminId, $segment, fn () => ['sendVideoNote', array_filter(['video_note' => $videoNote['file_id'], 'duration' => $videoNote['duration'] ?? null, 'length' => $videoNote['length'] ?? null])]);
        }
        if ($segment && $text !== '') {
            return $this->broadcast($adminId, $segment, fn () => ['sendMessage', ['text' => $text, 'reply_markup' => ['remove_keyboard' => true]]]);
        }

        return $this->mainMenu();
    }

    private function reply(string $text, array $buttons = []): array
    {
        return ['text' => $text, 'reply_markup' => $buttons ? ['keyboard' => array_chunk($buttons, 2), 'resize_keyboard' => true] : ['remove_keyboard' => true]];
    }

    private function mainMenu(): array
    {
        return $this->reply('🛠 Admin panel. Quyidagilardan birini tanlang:', [self::MENU_STATS, self::MENU_REMINDERS]);
    }

    private function remindersMenu(): array
    {
        return $this->reply('Qaysi guruhga eslatma yubormoqchisiz?', array_keys(self::SEGMENTS));
    }

    private function stats(): array
    {
        $target = config('dataset.target');
        $base = Participant::whereNull('deletion_requested_at');
        $total = (clone $base)->count();
        $wakeWordDone = (clone $base)->where('recording_count', '>=', $target)->count();
        $noHardNegative = (clone $base)->where('hard_negative_count', 0)->count();
        $wakeWordTotal = (int) (clone $base)->sum('recording_count');
        $hardNegativeTotal = (int) (clone $base)->sum('hard_negative_count');

        return $this->reply(
            "📊 Statistika\nIshtirokchilar: {$total}\n\n🎙 Hoy, Malika ovozlari: {$wakeWordTotal}\nTarget’ga yetganlar: {$wakeWordDone} / {$total}\n\n🔀 O‘xshash so‘z ovozlari: {$hardNegativeTotal}\nHali yubormaganlar: {$noHardNegative} / {$total}",
            [self::MENU_REMINDERS]
        );
    }

    private function cacheKey(int $adminId): string
    {
        return 'admin:pending_broadcast:'.$adminId;
    }

    private function pendingSegment(int $adminId): ?string
    {
        return Cache::get($this->cacheKey($adminId));
    }

    private function segmentLabel(string $segment): string
    {
        return array_search($segment, self::SEGMENTS, true);
    }

    private function askForContent(int $adminId, string $segment): array
    {
        Cache::put($this->cacheKey($adminId), $segment, now()->addMinutes(15));

        return $this->reply(
            "✏️ “{$this->segmentLabel($segment)}” guruhiga yubormoqchi bo‘lgan xabaringizni shu yerga yozing yoki doira video xabar (video note) yuboring.\n\nStandart (avtomatik) xabarni ishlatish uchun pastdagi tugmani bosing.",
            [self::USE_DEFAULT, self::CANCEL]
        );
    }

    private function segmentMatcher(string $segment): Closure
    {
        return match ($segment) {
            'wake_word' => fn (Participant $p) => $p->recording_count < config('dataset.target'),
            'hard_negative' => fn (Participant $p) => $p->hard_negative_count < 1,
            'either' => fn (Participant $p) => $p->recording_count < config('dataset.target') || $p->hard_negative_count < 1,
        };
    }

    private function defaultMessage(string $segment, Participant $p): string
    {
        return match ($segment) {
            'wake_word' => $this->wakeWordMessage($p),
            'hard_negative' => $this->hardNegativeMessage($p),
            'either' => $this->combinedMessage($p),
        };
    }

    /** @param  Closure(Participant): array{0: string, 1: array}  $content  Returns [telegramMethod, params] for a recipient. */
    private function broadcast(int $adminId, string $segment, Closure $content): array
    {
        Cache::forget($this->cacheKey($adminId));
        $matches = $this->segmentMatcher($segment);
        $count = 0;
        Participant::whereNull('deletion_requested_at')->where('is_blocked', false)->where('consent_given', true)
            ->chunkById(200, function ($participants) use ($matches, $content, &$count) {
                foreach ($participants as $p) {
                    if (! $matches($p)) {
                        continue;
                    }
                    [$method, $params] = $content($p);
                    SendBroadcastMessage::dispatch($p->telegram_user_id, $method, $params);
                    $count++;
                }
            });

        return $this->reply("✅ Xabar {$count} ta ishtirokchiga navbatga qo‘yildi.", [self::MENU_STATS, self::MENU_REMINDERS]);
    }

    private function greeting(Participant $p): string
    {
        return $p->first_name ? ', '.$p->first_name : '';
    }

    private function wakeWordMessage(Participant $p): string
    {
        $remaining = max(0, config('dataset.target') - $p->recording_count);

        return "Salom{$this->greeting($p)}! 🎙 Siz hozircha {$p->recording_count} ta “Hoy, Malika” ovozini yubordingiz. Yana {$remaining} ta yuborsangiz, loyihaga katta yordam bo‘ladi. Botga qaytib davom eting!";
    }

    private function hardNegativeMessage(Participant $p): string
    {
        return "Salom{$this->greeting($p)}! 🔀 Siz hali “o‘xshash so‘z” namunalarini yubormagansiz. Botda “🔀 Boshqa (o‘xshash) so‘z yuboraman” tugmasini bosib, “Hoy, Malika”ga o‘xshash boshqa so‘zlarni ham yuborishingiz mumkin — bu modelni yanada aniqroq qiladi!";
    }

    private function combinedMessage(Participant $p): string
    {
        $parts = [];
        if ($p->recording_count < config('dataset.target')) {
            $remaining = config('dataset.target') - $p->recording_count;
            $parts[] = "🎙 “Hoy, Malika”dan yana {$remaining} ta";
        }
        if ($p->hard_negative_count < 1) {
            $parts[] = '🔀 kamida bitta “o‘xshash so‘z”';
        }

        return "Salom{$this->greeting($p)}! Sizdan ".implode(' va ', $parts)." ovoz kutyapmiz. Botga qaytib davom etsangiz bo‘ladi 🙏";
    }
}
