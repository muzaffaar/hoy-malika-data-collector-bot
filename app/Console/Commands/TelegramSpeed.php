<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use GuzzleHttp\TransferStats;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class TelegramSpeed extends Command
{
    protected $signature = 'telegram:speed {--samples=5 : Requests per mode}';

    protected $description = 'Measure how fast this server reaches Telegram, PostgreSQL and Redis (finds out why the bot is slow)';

    public function handle(): int
    {
        $samples = max(1, min(20, (int) $this->option('samples')));
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;   // not available on Windows
        $this->line('Server load average (1/5/15 min): '.($load ? implode(' / ', array_map(fn ($l) => number_format($l, 2), $load)) : 'unknown').'   (compare with your CPU count: nproc)');
        $this->line(sprintf('PostgreSQL round trip: %s   Redis round trip: %s',
            $this->timed(fn () => DB::select('select 1')), $this->timed(fn () => Redis::connection()->ping())));

        $token = (string) config('telegram.token');
        if ($token === '') {
            $this->error('TELEGRAM_BOT_TOKEN is not set.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Telegram API (getMe), averages over '.$samples.' requests. Every bot call opens a NEW connection, so DNS + TCP + TLS is paid each time.');
        $results = [];
        foreach (['Default routing' => [], 'IPv4 only' => ['force_ip_resolve' => 'v4']] as $mode => $options) {
            $results[$mode] = $this->probe($token, $options, $samples);
        }
        $this->table(['Mode', 'DNS ms', 'TCP ms', 'TLS ms', 'Wait ms', 'Total ms', 'IP used', 'Failed'],
            collect($results)->map(fn ($r, $mode) => [$mode, $r['dns'], $r['tcp'], $r['tls'], $r['wait'], $r['total'], $r['ip'], $r['failed'].'/'.$samples])->values()->all());

        $this->keepAliveLine($samples);
        $this->advise($results);

        return self::SUCCESS;
    }

    private function probe(string $token, array $options, int $samples): array
    {
        $sum = ['dns' => [], 'tcp' => [], 'tls' => [], 'wait' => [], 'total' => []];
        $ip = '-';
        $failed = 0;
        $reason = null;
        for ($i = 0; $i < $samples; $i++) {
            $stats = null;
            $started = hrtime(true);
            try {
                $response = Http::connectTimeout(10)->timeout(20)->withOptions($options + ['on_stats' => function (TransferStats $s) use (&$stats) {
                    $stats = $s->getHandlerStats();
                }])->get('https://api.telegram.org/bot'.$token.'/getMe');
                if (! $response->successful()) {
                    $failed++;
                    $reason ??= 'HTTP '.$response->status().' from Telegram'.($response->status() === 401 ? ' (bot token rejected)' : '');
                }
            } catch (\Throwable $e) {
                $failed++;
                // curl messages embed the request URL, which contains the bot token: never print it.
                $reason ??= mb_substr(trim((string) preg_replace('~\s+for https://\S+~', '', str_replace($token, '<token>', $e->getMessage()))), 0, 200);
            }
            $sum['total'][] = (hrtime(true) - $started) / 1e6;
            if (is_array($stats) && isset($stats['namelookup_time'])) {
                $sum['dns'][] = $stats['namelookup_time'] * 1000;
                $sum['tcp'][] = ($stats['connect_time'] - $stats['namelookup_time']) * 1000;
                $sum['tls'][] = (($stats['appconnect_time'] ?? 0) ?: $stats['connect_time']) * 1000 - $stats['connect_time'] * 1000;
                $sum['wait'][] = ($stats['starttransfer_time'] - $stats['pretransfer_time']) * 1000;
                $ip = (string) ($stats['primary_ip'] ?? $ip);
            }
        }
        $avg = fn (array $v) => $v ? (string) round(array_sum($v) / count($v)) : '-';

        return ['dns' => $avg($sum['dns']), 'tcp' => $avg($sum['tcp']), 'tls' => $avg($sum['tls']), 'wait' => $avg($sum['wait']), 'total' => $avg($sum['total']),
            'ip' => $ip, 'failed' => $failed, 'reason' => $reason, 'total_ms' => $sum['total'] ? array_sum($sum['total']) / count($sum['total']) : null,
            'setup_ms' => $sum['dns'] ? (array_sum($sum['dns']) + array_sum($sum['tcp']) + array_sum($sum['tls'])) / count($sum['dns']) : null];
    }

    /** What the bot itself does: one kept-alive connection, so only the first call pays DNS + TCP + TLS. */
    private function keepAliveLine(int $samples): void
    {
        try {
            $client = app(TelegramClient::class);
            $times = [];
            for ($i = 0; $i <= $samples; $i++) {
                $started = hrtime(true);
                $client->call('getMe');
                $times[] = (hrtime(true) - $started) / 1e6;
            }
            $this->info(sprintf('Bot client (connection kept alive, as the bot runs): first call %d ms, following calls %d ms on average.', $times[0], array_sum(array_slice($times, 1)) / max(1, count($times) - 1)));
        } catch (\Throwable) {
            $this->line('Bot client check skipped (Telegram requests failed).');
        }
    }

    private function advise(array $results): void
    {
        $default = $results['Default routing'];
        $v4 = $results['IPv4 only'];
        $this->newLine();
        foreach (['Default routing' => $default, 'IPv4 only' => $v4] as $mode => $r) {
            if ($r['failed'] > 0) {
                $this->error($mode.': '.$r['failed'].' request(s) failed - '.$r['reason']);
            }
        }
        if ($default['failed'] > 0 || $v4['failed'] > 0) {
            $this->warn('Some Telegram requests failed, so the timings above are incomplete. Check outbound access to api.telegram.org (firewall, DNS, CA certificates), then run again.');

            return;
        }
        if ($default['total_ms'] !== null && $v4['total_ms'] !== null && $default['total_ms'] - $v4['total_ms'] > 200 && $v4['total_ms'] < $default['total_ms'] * 0.7) {
            $this->warn('IPv4-only is much faster: this server has a slow/broken IPv6 route. Set TELEGRAM_FORCE_IPV4=true in .env and recreate the containers.');
        }
        if (($default['setup_ms'] ?? 0) > 250) {
            $this->warn(sprintf('Connection setup (DNS+TCP+TLS) costs ~%d ms per Telegram call and a voice needs 3 calls (getFile, download, reply): ~%d ms of pure overhead.', $default['setup_ms'], 3 * $default['setup_ms']));
        }
        if (is_numeric($default['wait']) && $default['wait'] > 500) {
            $this->warn('Telegram itself answers slowly from this server (Wait ms); the network route is the bottleneck, not the workers.');
        }
        if ($default['total_ms'] !== null && $default['total_ms'] < 400) {
            $this->info('Telegram is reached quickly from this server; if the bot still feels slow look at the load average, dataset:latency and "telegram.slow_" log lines.');
        }
    }

    private function timed(callable $probe): string
    {
        try {
            $times = [];
            for ($i = 0; $i < 20; $i++) {
                $t = hrtime(true);
                $probe();
                $times[] = (hrtime(true) - $t) / 1e6;
            }

            return number_format(array_sum($times) / count($times), 1).' ms';
        } catch (\Throwable) {
            return 'unavailable';
        }
    }
}
