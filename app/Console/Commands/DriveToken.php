<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class DriveToken extends Command
{
    private const SCOPE = 'https://www.googleapis.com/auth/drive';

    protected $signature = 'dataset:drive-token {--code= : The code (or the whole address) copied from the browser after approving access} {--redirect=http://localhost : Redirect URI, must be allowed for the OAuth client}';

    protected $description = 'Create a Google Drive refresh token for the exact client ID/secret in .env (avoids unauthorized_client mismatches)';

    public function handle(): int
    {
        $clientId = (string) config('dataset.drive_client_id');
        $secret = (string) config('dataset.drive_client_secret');
        if ($clientId === '' || $secret === '') {
            $this->error('Set GOOGLE_DRIVE_CLIENT_ID and GOOGLE_DRIVE_CLIENT_SECRET in .env first.');

            return self::FAILURE;
        }
        $redirect = (string) $this->option('redirect');

        if (! $this->option('code')) {
            $this->line('1. Open this address in a browser and sign in with the Google account that owns/can edit the Drive folder:');
            $this->newLine();
            $this->line('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
                'client_id' => $clientId, 'redirect_uri' => $redirect, 'response_type' => 'code', 'scope' => self::SCOPE,
                'access_type' => 'offline', 'prompt' => 'consent',
            ]));
            $this->newLine();
            $this->line('2. Approve access. The browser then shows an error page ("this site can\'t be reached"). That is expected.');
            $this->line('3. Copy the WHOLE address from the browser address bar (it contains ?code=...) and run:');
            $this->line("   php artisan dataset:drive-token --code='<pasted address>'");

            return self::SUCCESS;
        }

        $code = trim((string) $this->option('code'));
        if (str_contains($code, 'code=')) {
            parse_str((string) parse_url($code, PHP_URL_QUERY), $query);
            $code = (string) ($query['code'] ?? $code);
        }

        $response = Http::asForm()->connectTimeout(10)->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'code' => urldecode($code), 'client_id' => $clientId, 'client_secret' => $secret,
            'redirect_uri' => $redirect, 'grant_type' => 'authorization_code',
        ]);
        if (! $response->successful() || ! $response->json('refresh_token')) {
            $error = (string) ($response->json('error') ?? 'HTTP '.$response->status());
            $this->error('Google refused: '.$error.': '.$response->json('error_description'));
            $this->line(match ($error) {
                'invalid_grant' => 'The code is single-use and expires within minutes. Start again from step 1 and use the fresh code.',
                'redirect_uri_mismatch' => 'The OAuth client does not allow this redirect URI. Use a "Desktop app" client, or add the URI to the client.',
                'invalid_client', 'unauthorized_client' => 'The client ID/secret in .env are not valid for this flow. Check them in Google Cloud Console.',
                default => 'If no refresh_token was returned, remove the app at https://myaccount.google.com/permissions and repeat.',
            });

            return self::FAILURE;
        }
        // Whole-word match: ".../auth/drive.file" must not pass as ".../auth/drive".
        if (! in_array(self::SCOPE, explode(' ', (string) $response->json('scope')), true)) {
            $this->warn('The granted scope is "'.$response->json('scope').'", which cannot write into your folder. Tick full Google Drive access on the consent screen.');
        }

        $this->info('Success. Put this in the server .env, then run: docker compose up -d --force-recreate app queue-drive');
        $this->newLine();
        $this->line('GOOGLE_DRIVE_REFRESH_TOKEN='.$response->json('refresh_token'));
        $this->newLine();
        $this->line('Then verify with: php artisan dataset:doctor');

        return self::SUCCESS;
    }
}
