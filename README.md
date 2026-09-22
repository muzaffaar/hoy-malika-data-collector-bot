# Hoy, Malika — Telegram Dataset Collector

Docker-first Laravel application for collecting intact positive **“Hoy, Malika”** Telegram voice samples from Uzbek participants. The bot uses **long polling only** (no webhook), persists onboarding, stores immutable originals, exposes a protected admin dashboard, optionally backs up to Google Drive, and supports a checksum-verifying pull agent for a local computer.

## Architecture

`Telegram -> telegram-polling -> PostgreSQL durable inbox -> Redis queues -> immutable dataset storage -> Google Drive / local sync agent`

Docker services: `nginx`, `app`, `postgres`, `redis`, `queue`, `queue-replies`, `queue-drive`, `scheduler`, `telegram-polling`.

## Quick start

1. Copy configuration: `cp .env.example .env`.
2. Set strong values for `DB_PASSWORD`, `TELEGRAM_BOT_TOKEN`, `LOCAL_SYNC_API_TOKEN`, `ADMIN_EMAIL`, and `ADMIN_PASSWORD`.
3. Set `APP_URL` to the production HTTPS URL. If TLS terminates at a trusted reverse proxy, set `TRUSTED_PROXIES` to its exact IP/CIDR.
4. Choose host dataset location with `DATASET_HOST_PATH`. The default is `./docker-data/dataset`; for a VPS, `/srv/hoy-malika/dataset` is recommended.
5. Build: `docker compose build`.
6. Generate an app key: `docker compose run --rm app php artisan key:generate --show`, then put the result into `APP_KEY` in `.env`.
7. Start infrastructure: `docker compose up -d postgres redis app nginx`.
8. Migrate: `docker compose exec app php artisan migrate --force`.
9. Seed admin: `docker compose exec app php artisan db:seed --class=AdminSeeder --force`.
10. Validate: `docker compose exec app php artisan dataset:doctor`.
11. Start all services: `docker compose up -d`.

Dashboard: `APP_URL/admin/login`.

## Hard negatives

Besides positive "Hoy, Malika" samples, the bot can also collect **hard negatives** — recordings of a phrase that sounds similar to "Hoy, Malika" but is not it, used to train the wake-word model to reject false positives. Once a participant reaches `READY_FOR_RECORDINGS`, every prompt shows two buttons: "🎙 “Hoy, Malika” yuboraman" and "🔀 Boshqa (o‘xshash) so‘z yuboraman". Tapping a button sets the participant's current `collection_mode`, which tags every subsequent voice message until switched again; `/status` reports both counts. Hard negatives never count toward `TARGET_RECORDINGS_PER_USER` or advance `onboarding_state`.

Hard negatives are stored under a separate tree (`dataset/hard_negative/YYYY/MM/DD/`, configurable via `DATASET_HARD_NEGATIVE_BASE_PATH` equivalent `hard_negative_base_path` in `config/dataset.php`) so they never mix with positive originals on disk or in exports. In the admin panel, **Recordings** has a "Sample type" filter and a Type column, the participant and recording detail pages show hard-negative counts, and `dataset:stats` / `dataset:export` break totals down by `sample_type`.

## Telegram polling

`telegram-polling` runs `php artisan telegram:poll`. It uses Telegram `getUpdates`, persists each update in PostgreSQL before processing, tracks a durable cursor, and dispatches per-user processing through Redis. No webhook route is configured.

Useful logs:

```bash
docker compose logs -f telegram-polling
docker compose logs -f queue
docker compose logs -f queue-replies
```

## Parallel workers

`docker compose up -d` starts `QUEUE_WORKERS` (default 10) voice-processing workers, `REPLY_WORKERS` (4) Telegram reply senders and `DRIVE_WORKERS` (3) Google Drive uploaders. Override them in `.env`, or ad hoc with `docker compose up -d --scale queue=20`. Messages from different participants are processed in parallel; one participant's messages stay strictly ordered by a per-participant Redis lock, so extra workers help only when many people send at once. **Many users at once.** Updates are stored in PostgreSQL first, then only each participant's oldest unprocessed message is queued; finishing it queues the next one, so one participant's messages stay in order while different participants run in parallel on separate workers. Idle workers wait on Redis (`REDIS_QUEUE_BLOCK_FOR`, default 2 s) and start a job the moment it is queued. Rough capacity with the defaults: a voice needs about 1-1.5 s of worker time (mostly the Telegram download), so 10 workers handle roughly 400-600 voices per minute; text-only answers take milliseconds. Confirmations are limited by Telegram (about 30 messages/s across all chats, ~2 per second per reply worker), so raise `REPLY_WORKERS` (e.g. 10) before `QUEUE_WORKERS` if replies queue up. Watch it with `php artisan dataset:latency`: a growing `telegram-processing` backlog means more `QUEUE_WORKERS`, a growing `telegram-replies` backlog means more `REPLY_WORKERS`.

Keep exactly **one** `telegram-polling` and one `scheduler`. Each idle worker uses about 35 MB and one PostgreSQL connection (default limit 100).

## Persistent originals

Original Telegram bytes are written under the dataset mount, by default:

`./docker-data/dataset/dataset/original/YYYY/MM/DD/`

For production set:

`DATASET_HOST_PATH=/srv/hoy-malika/dataset`

**Linux ownership (the usual cause of "Ovozni saqlashda vaqtinchalik muammo").** The containers run as `www-data` (uid 33). Docker creates a missing host folder as `root`, so voices cannot be written until you run once:

```bash
sudo mkdir -p /srv/hoy-malika/dataset && sudo chown -R 33:33 /srv/hoy-malika/dataset
```

`docker compose exec app php artisan dataset:doctor` (and the admin **System health** page) run a real write probe against this folder and print the exact reason if it fails. A failed voice is kept and retried automatically with growing delays (up to 1 hour). After fixing the cause, release them immediately:

```bash
docker compose exec postgres sh -c 'psql -U $POSTGRES_USER -d $POSTGRES_DB -c "update telegram_updates set available_at = null where processed_at is null;"'
```

Files are downloaded to `.part`, size checked, SHA-256 hashed, fsynced, atomically renamed, and made read-only. The application never transcodes an original. Recreating application containers does not remove this host directory.

**Never use destructive volume/data commands without understanding their effect.** In particular, do not remove the dataset host directory and do not use `docker compose down -v` as a routine deployment command.

## Google Drive

Set:

```env
GOOGLE_DRIVE_BACKUP_ENABLED=true
GOOGLE_DRIVE_FOLDER_ID=...
GOOGLE_DRIVE_CLIENT_ID=...
GOOGLE_DRIVE_CLIENT_SECRET=...
GOOGLE_DRIVE_REFRESH_TOKEN=...
```

The configured folder ID is the parent folder. The queue creates `original/YYYY/MM/DD`, uploads asynchronously, and verifies Drive-reported size and SHA-256 before marking a recording complete. Drive failure never deletes the server original.

Retry failed/pending backups:

`docker compose exec app php artisan dataset:backup-retry`

To create a refresh token that is guaranteed to match the client ID/secret in `.env`, run `docker compose exec app php artisan dataset:drive-token` and follow the printed steps (use an OAuth client of type *Desktop app*; tokens made in the OAuth Playground without "Use your own OAuth credentials" cause `unauthorized_client`).

`docker compose exec app php artisan dataset:doctor` makes a live call to Google and prints why a login is rejected. If it reports `unauthorized_client` or `invalid_grant`, issue a new refresh token **with the same client ID and secret that are in `.env`**, using the full `https://www.googleapis.com/auth/drive` scope (narrower scopes cannot write into a folder the app did not create). If the OAuth consent screen is in *Testing* mode Google expires refresh tokens after 7 days, so set it to *In production*. After updating `GOOGLE_DRIVE_REFRESH_TOKEN`, run `docker compose up -d` (reloads `.env`) and then `dataset:backup-retry`.

## Continuous local-machine copy

The local sync agent is intentionally outside the VPS Docker stack. Copy `tools/dataset-sync/sync.py` to the authorized computer and configure:

```env
SERVER_URL=https://your-domain.example
API_TOKEN=<same value as LOCAL_SYNC_API_TOKEN>
LOCAL_DATASET_PATH=D:\datasets\hoy-malika
POLL_INTERVAL_SECONDS=30
```

Linux example:

```bash
SERVER_URL=https://your-domain.example \
API_TOKEN='...' \
LOCAL_DATASET_PATH=/data/hoy-malika \
python3 tools/dataset-sync/sync.py
```

Generate the token with `openssl rand -hex 32` and put the same value in the server's `LOCAL_SYNC_API_TOKEN`. An empty or short (<32 chars) token makes the sync API answer `401` for everything; `dataset:doctor` reports this. The sync API also requires HTTPS, and the agent refuses `http://` URLs, so it cannot pull from `localhost`. Behind a TLS-terminating reverse proxy set `TRUSTED_PROXIES` to that proxy's IP, otherwise Laravel sees plain HTTP and answers `403 HTTPS required` (and secure session cookies stop working).

The agent downloads to `.part`, verifies size + SHA-256, atomically renames, then acknowledges completion. Existing mismatching files are never silently overwritten. `tools/dataset-sync/dataset-sync.service` contains a systemd example. On Windows, run the same Python script through Task Scheduler at logon/startup with environment variables configured for the task/user.

## Listening in the admin panel

Every row in **Recordings** and on each participant page has a ▶ button that streams the original through the authenticated `admin/recordings/{id}/download` route (byte-range seeking supported; only one recording plays at a time). The recording detail page has a full player. Files failing their SHA-256 check are refused instead of played, and the button turns red with a hint.

## Admin sessions and security

Admin authentication uses Laravel sessions stored in PostgreSQL, CSRF protection, login throttling, session regeneration, idle timeout, encrypted cookies, and secure cookies in production. Audio is private and only streamed/downloaded through authenticated routes. The sync API requires a >=32-character bearer token and HTTPS.

## Dataset operations

```bash
docker compose exec app php artisan dataset:stats
docker compose exec app php artisan dataset:verify
docker compose exec app php artisan dataset:export
docker compose exec app php artisan dataset:doctor
```

`dataset:verify` recalculates size and SHA-256 for every original and reports integrity failures.

`telegram:speed` answers "why is the bot slow?": it measures DNS, TCP, TLS and Telegram's own answer time from this server (default routing and IPv4-only), the PostgreSQL and Redis round trips, the server load, and the speed of the bot's kept-alive connection. The bot reuses one open connection to Telegram per worker process instead of opening a new one for every call. Any Telegram call slower than 1.5 s or job slower than 2 s is logged as `telegram.slow_call` / `telegram.slow_update` with the stage, so `docker compose logs queue queue-replies | grep slow_` shows where time went.

`dataset:latency [--minutes=60]` shows where time goes: received -> processed and reply queued -> delivered (average, p95, max), updates that keep failing with their last error, queue backlog per queue, and failed jobs. Run it first when the bot feels slow. Failed steps are retried on time (after 3 s, 6 s, 12 s, ...) instead of waiting for the once-a-minute scheduler sweep.

## Production updates

```bash
git pull
docker compose build
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan optimize
```

Then inspect `docker compose ps` and the polling/queue logs.

## Tests

Inside an environment with Composer dependencies installed:

```bash
php artisan test
```

Or use the runtime image after development dependencies are included in a test build. The suite covers authentication, date boundaries, inbox idempotency, statistics, and local-sync integrity acknowledgement.

## Data-safety deployment check

Before public collection: send test voices, record their SHA-256 values, run `docker compose down`, then `docker compose up -d`; verify files and PostgreSQL rows still exist and checksums are unchanged. Rebuild the images and repeat. Only begin collection after this passes.

## Operational notes

- Keep `APP_DEBUG=false` in production.
- PostgreSQL and Redis are not published to the host/public network by Compose.
- The bot confirmation is created only after the original is safely stored and the database transaction records it.
- Google Drive and local-computer synchronization are asynchronous and cannot block Telegram collection.
- Participant deletion is audited and waits for tracked external copies to be removed/acknowledged.
