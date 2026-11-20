# Deployment & Operations

Reference deployment for a CloudPanel-style Ubuntu host (any PHP-FPM + Nginx + PostgreSQL
host works the same). Replace `<site-user>` and `<your-domain>` with your values.

The application is **deliberately self-isolated**: one database, one DB role, one PHP-FPM
pool, one systemd worker, one crontab entry — nothing shared with other apps on the host
is modified.

## Isolation summary

| Resource | Choice |
|---|---|
| Database | Dedicated `promptedit_prod` database + dedicated non-superuser role `promptedit_app` |
| Cache / sessions / queue | `database` driver — everything lives inside `promptedit_prod` (no shared Redis keyspace) |
| Queue worker | Dedicated `promptedit-worker.service` systemd unit (see below) |
| Scheduler | A single crontab entry for the site user (that user has no other crontab) |
| PHP | 8.4 FPM, dedicated pool listening on `127.0.0.1:19001` (`pm = ondemand`) |
| Frontend assets | Built off-server (`npm run build`) and shipped as `public/build` — Node.js is not needed on the server |
| Webhook ingress | `POST https://<your-domain>/api/webhooks/pixelforge` — the only unauthenticated route, HMAC-verified |

## First deployment

1. Create the database (as a sudo-capable user, via the postgres superuser):

   ```bash
   sudo -u postgres psql <<'SQL'
   CREATE ROLE promptedit_app LOGIN PASSWORD '<STRONG PASSWORD>';
   CREATE DATABASE promptedit_prod OWNER promptedit_app;
   GRANT ALL PRIVILEGES ON DATABASE promptedit_prod TO promptedit_app;
   GRANT ALL ON SCHEMA public TO promptedit_app;
   SQL
   ```

2. Copy the app into the site directory (code + `vendor/` + built `public/build`).

3. As the site user, create `.env` from `.env.example`:

   - `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<your-domain>`
   - `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5432`,
     `DB_DATABASE=promptedit_prod`, `DB_USERNAME=promptedit_app`, `DB_PASSWORD=…`
   - `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`
   - `LOG_CHANNEL=daily`, `LOG_LEVEL=warning`, `MAIL_LOG_CHANNEL=mail`
   - `PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET`, `PIXELFORGE_MARKUP=1.25`
   - `ADMIN_EMAIL` / `ADMIN_PASSWORD` (used once by the seeder)

   `chmod 640 .env` (site-user only).

4. Initialize:

   ```bash
   php8.4 artisan key:generate
   php8.4 artisan migrate --seed
   php8.4 artisan storage:link        # serve locally-archived generated images
   php8.4 artisan config:cache && php8.4 artisan route:cache && php8.4 artisan view:cache && php8.4 artisan event:cache
   ```

5. Install the worker:

   ```bash
   sudo cp deploy/promptedit-worker.service /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now promptedit-worker
   ```

6. Install the scheduler (site user's crontab — keep it to this single line):

   ```cron
   * * * * * /usr/bin/php8.4 /home/<site-user>/htdocs/<your-domain>/artisan schedule:run >> /home/<site-user>/logs/scheduler.log 2>&1
   ```

7. In the Pixelforge dashboard set the API key's webhook URL to
   `https://<your-domain>/api/webhooks/pixelforge`, then press **Send test event** and
   confirm a `ping` row in `webhook_delivery_logs` (status `unknown_generation`, HTTP 200,
   valid signature — pings carry no generation id, which is the correct outcome).

## Scheduled jobs

| Job | Schedule | Purpose |
|---|---|---|
| `promptedit:sync-stuck` | every minute | Re-dispatch polling for generations stuck in flight (webhook-loss fallback) |
| `promptedit:reconcile-usage --days=30` | daily 03:07 | Reconcile our books against `GET /v1/usage` |

## Operations runbook

- **Deploy code changes:** copy files → `php8.4 artisan config:cache` (etc.) →
  `php8.4 artisan queue:restart` (systemd restarts the worker).
- **Run the test suite on a host that has a production `.env`:** always
  `php8.4 artisan config:clear` first. `phpunit.xml` forces `APP_ENV=testing`,
  SQLite `:memory:` and fake provider credentials (`force="true"`), so the suite
  cannot touch production data. Rebuild the caches afterwards.
- **Never** run `migrate:fresh`, `db:wipe` or destructive seeders against the production
  database.
- **Rotate the webhook signing secret** (if ever exposed) from the Pixelforge dashboard
  (Webhooks → Rotate secret), update `PIXELFORGE_WEBHOOK_SECRET` in `.env`, re-run
  `config:cache`, and press Send test event to verify.
- **Logs:** application `storage/logs/laravel-*.log` (level warning), mail
  `storage/logs/mail-*.log`, worker `~/logs/worker*.log`, scheduler `~/logs/scheduler.log`.
