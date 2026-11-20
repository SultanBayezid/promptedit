# PromptEdit Studio

AI image-generation SaaS built with **Laravel 13, Vue 3, Inertia 2, Tailwind CSS** and **PostgreSQL**,
integrating the asynchronous [Pixelforge](https://pixelforge.promptedit.dev/docs) image-generation API.

**Live application:** https://promptedit.lastlineweb.com

PromptEdit lets users write a prompt, pick size, quality and optional reference images, see an
**exact pre-submission price range**, and submit the request. Generations complete asynchronously
(webhook-first, with a polling fallback); the customer is billed the **actual provider cost plus exactly
25 %**, to the nano-dollar, and **failed generations are always free**. A credit-balance system with an
immutable ledger backs every charge, and an admin panel provides daily / per-user financial reporting
plus live reconciliation against the provider's own usage records.

---

## 1. Project overview

This is a take-home assessment build for a Senior Product Engineer role. The interesting engineering
problems it solves:

- **Exact money math** — every amount is an integer number of **nano-dollars (1e-9 USD)**; floats are
  never used for money. Provider prices (9-decimal strings) are parsed, multiplied, marked up (×1.25,
  round-half-up at nano precision) and stored entirely with `bcmath`/integer arithmetic.
- **Estimates vs. actuals** — token counts (input text ±15 %, reasoning 200–8 000 by quality) are only
  known after completion, so the app shows honest min/max bounds up front, places a **hold** for the
  worst case at submission, then **settles to the exact actual** charge on success (or releases the hold
  on failure).
- **Asynchronous, failure-prone provider** — submission retries (503/429/network) with a stable
  `Idempotency-Key`, webhooks are the primary completion signal, a polling job and a per-minute
  scheduler sweep are the fallback; a forward-only state machine makes out-of-order and duplicate
  webhook events harmless.
- **Financial integrity** — an append-only credit ledger where `balance == SUM(ledger)` is an invariant;
  duplicate events can never double-charge; the admin reports reconcile our books against
  `GET /v1/usage`, the provider's own source of truth.

## 2. Application features

- **Auth** — register, login, logout, password reset (via the `log` mailer by default), profile
  management (Laravel Breeze, Inertia edition).
- **Generate** — prompt (≤4 000 chars), size (`1024x1024`, `1536x1024`, `1024x1536`), quality
  (`low`/`medium`/`high`), up to 4 reference-image URLs, and a **live cost estimate** (token bounds,
  rate breakdown, provider cost range, charge range) refreshed as you type.
- **History** — per-user list with status filter, prompt search and pagination; status badges for
  queued / processing / succeeded / failed.
- **Generation detail** — auto-polling while in flight; on completion the exact token counts,
  per-component cost breakdown, estimated vs. actual comparison, and the billing ledger entries.
  Generated images are downloaded to local storage (the provider's signed URLs expire after 24 h).
- **Credits (bonus)** — balance page with the full ledger; mock purchases ($1–$1 000) create purchase
  rows; submissions place a hold for the maximum possible charge; insufficient balance blocks the
  request atomically.
- **Admin (bonus)** — platform totals, user table, recent generations, webhook delivery audit; and the
  **financial report**: daily P&L, per-user and per-generation views with date filters, plus a live
  **reconciliation against Pixelforge `GET /v1/usage`**.

## 3. Technology stack

| Layer | Choice |
|---|---|
| Backend | Laravel 13 (PHP 8.4) |
| Frontend | Vue 3 + Inertia 2 + Tailwind CSS (Vite build) |
| Database | PostgreSQL 16 (SQLite in-memory for the test suite) |
| Queue | Laravel `database` driver, dedicated `systemd` worker |
| Cache / sessions | `database` driver (single-database isolation; no shared Redis) |
| Scheduler | Laravel scheduler via a single per-user crontab entry |
| HTTP client | Laravel Http (Pixelforge REST API) |

## 4. Architecture overview

```
Browser ──Inertia──► GenerationController
                       │  POST /generate
                       ▼
              GenerationService::submit()      (single DB transaction)
                ├─ EstimateService   ─ exact min/max token+cost bounds from live pricing
                ├─ BillingService    ─ placeHold(worst-case charge)  [row-locked, ledger row]
                └─ dispatch(SubmitGeneration)   [database queue, after_commit]

systemd worker ──► SubmitGeneration job
                ├─ POST /v1/generations  (Idempotency-Key, 202)
                ├─ 429 → release(Retry-After)   503 → backoff retry (same key)
                ├─ 400 → permanent failure      (never billed)
                └─ dispatch(PollGeneration, delay 90 s)   [fallback signal]

Pixelforge ──signed webhook──► POST /api/webhooks/pixelforge   (primary signal)
                ├─ HMAC-SHA256 verify (constant-time, ±300 s)
                ├─ event-id dedup  →  duplicates 200-acked, ignored
                └─ GET /v1/generations/{id}  (authoritative state)
                       ▼
              GenerationService::applyProviderPayload()
                ├─ succeeded → exact cost from usage × rates → settle (hold reconciliation)
                └─ failed    → releaseHold (failures are free)

Scheduler (cron, every minute):
  promptedit:sync-stuck      re-dispatch polls for in-flight generations
  promptedit:reconcile-usage daily reconciliation vs GET /v1/usage (03:07)
```

Key design rules: the webhook tells us **that** something changed, the provider API is the source of
truth for **what** changed; a generation is only "successful" when the provider says `succeeded` with a
usage payload; all billing mutations run in transactions holding row locks on the credit account, and
the `settled` billing state makes settle idempotent.

## 5. Requirements

- PHP 8.2+ (8.4 used in production; `ext-pdo_pgsql`, `ext-bcmath`, `ext-intl`, `ext-mbstring`)
- Composer 2
- Node.js 18+ / npm (build the frontend; not needed on the server if you build off-box)
- PostgreSQL 14+ (or SQLite for a quick local run / the test suite)

## 6. Local installation

```bash
git clone https://github.com/SultanBayezid/promptedit.git
cd promptedit

composer install
npm install

cp .env.example .env
php artisan key:generate

# quick local run (SQLite):
touch database/database.sqlite
php artisan migrate --seed        # ADMIN_EMAIL/ADMIN_PASSWORD in .env seed an admin

npm run dev                       # Vite dev server (or: npm run build)
php artisan serve                 # http://localhost:8000
```

## 7. Environment configuration

Copy `.env.example` and fill in the values. The important ones:

| Variable | Meaning |
|---|---|
| `DB_*` | Database connection (SQLite for local, PostgreSQL for production) |
| `QUEUE_CONNECTION=database` | Jobs live in the same database — keep this |
| `CACHE_STORE` / `SESSION_DRIVER` | `database` keeps everything in one isolated DB |
| `PIXELFORGE_API_KEY` | API key from the Pixelforge dashboard |
| `PIXELFORGE_WEBHOOK_SECRET` | The key's webhook signing secret (`whsec_…`) |
| `PIXELFORGE_MARKUP=1.25` | Customer charge = provider cost × markup |
| `PIXELFORGE_PRICING_CACHE_MINUTES=15` | Pricing cache TTL (persisted snapshot is the fallback) |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | Used once by `db:seed` to create the admin |

Never commit `.env`; the repository ships only `.env.example` with placeholders.

## 8. Database setup (PostgreSQL, production-style)

```sql
CREATE ROLE promptedit_app LOGIN PASSWORD '<strong password>';
CREATE DATABASE promptedit_prod OWNER promptedit_app;
GRANT ALL PRIVILEGES ON DATABASE promptedit_prod TO promptedit_app;
```

Use a **dedicated, non-superuser role and database**; the app needs no more than that.

## 9. Database migrations

```bash
php artisan migrate              # apply migrations
php artisan migrate --seed       # + seed the admin (ADMIN_EMAIL/ADMIN_PASSWORD)
php artisan migrate:fresh --seed # DEV/TEST ONLY — never against production
```

Migrations create `users` (with `is_admin`), `generations`, `credit_accounts`,
`financial_transactions`, `provider_events`, `provider_usage_records`, `webhook_delivery_logs`,
`pricing_snapshots` and Laravel's cache/jobs/sessions tables. All money columns are `bigint`
nano-dollars.

## 10. Pixelforge API configuration

1. Create an account at <https://pixelforge.promptedit.dev> and an API key in the dashboard.
2. Put the key in `PIXELFORGE_API_KEY` and the base URL (`https://pixelforge.promptedit.dev/v1`) in
   `PIXELFORGE_BASE_URL`.
3. Pricing is **never hardcoded** — it is fetched live from `GET /v1/pricing`, cached
   (`PIXELFORGE_PRICING_CACHE_MINUTES`), and persisted as a last-known-good `pricing_snapshots` row
   used when the provider is unreachable.

## 11. Webhook setup

In the Pixelforge dashboard set the key's **Endpoint URL** to your app's route:

```
https://<your-domain>/api/webhooks/pixelforge
```

and copy the key's signing secret into `PIXELFORGE_WEBHOOK_SECRET`. Webhook URLs must be publicly
reachable; use a tunnel (Expose/ngrok/Cloudflare) for local development. Press **Send test event** in
the dashboard to verify delivery — a `ping` should appear in the app's webhook audit log.

Verification (`app/Services/WebhookService.php`): `Pixelforge-Signature: t=<ts>,v1=HMAC-SHA256("<ts>".<raw body>, secret)`,
constant-time comparison, ±300 s timestamp tolerance, event-id dedup (payload-hash fallback when an id
is missing), and unknown generations are 200-acked to stop retry loops.

## 12. Queue worker setup

The app uses the `database` queue. Run the worker:

```bash
php artisan queue:work --sleep=1 --tries=3 --max-time=3600 --timeout=120
```

For production a `systemd` unit is included in [`deploy/promptedit-worker.service`](deploy/promptedit-worker.service)
(`sudo systemctl enable --now promptedit-worker`). Supervisor works equally well.

## 13. Scheduler configuration

One crontab entry, as the app's user:

```cron
* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
```

Scheduled jobs: `promptedit:sync-stuck` (every minute — re-dispatches polling for in-flight
generations) and `promptedit:reconcile-usage --days=30` (daily 03:07).

## 14. Running tests

```bash
php artisan test            # full suite (SQLite in-memory, faked Pixelforge HTTP)
php artisan test --group real-api   # optional: 3 tests against the LIVE Pixelforge API
```

The default suite is **62 tests / 238 assertions** (SQLite in-memory, faked provider HTTP) and covers: money parsing/formatting/markup (including the provider's own
worked example), estimate bounds, the full success pipeline (hold → settle → ledger consistency),
insufficient balance, failures-are-free, 400/429/503 retry semantics with idempotency-key reuse,
webhook signature/timestamp/duplicate/out-of-order/unknown-generation handling, settle idempotency,
admin authorization, per-user isolation, daily/per-user financial aggregation, reconciliation match
and mismatch detection, stuck-generation recovery and polling fallback.

> ⚠️ **Never run tests with a `config:cache` present and a production database configured** — clear
> the cache first (`php artisan config:clear`). The suite targets SQLite/`:memory:` via `phpunit.xml`.

## 15. Pricing calculation

- **Rates** come from `GET /v1/pricing` (per 1M tokens: input text, input image, output, reasoning).
- **Estimate (pre-submission)** — input text ≈ `ceil(chars/4)` ± 15 %; input images = 765 tokens each;
  output = exact size×quality matrix; reasoning = quality range `[min,max]`. The app computes a
  **min and max provider cost** from those bounds, applies the ×1.25 markup to each
  (round-half-up at nano precision), and displays the range. The **hold** at submission equals the
  worst-case charge.
- **Actual (on completion)** — `usage` tokens from the provider × the generation's own echoed `rates`
  (rates are fixed at creation), summed exactly in nano-dollars. The charge is
  `actual cost × 1.25`, settled against the hold in one ledger transaction.
- **Failures cost nothing** — the hold is released in full.

All arithmetic uses `bcmath` on 9-decimal strings / integer nano-dollars
(`app/Support/Money.php`); no floating point ever touches money.

## 16. Credit system

- Every user has a `credit_accounts` row; every mutation appends an immutable
  `financial_transactions` row (`purchase`, `hold`, `settle`, `release_hold`) with `balance_after`.
- `balance == SUM(ledger)` holds at all times (asserted by tests).
- Submissions hold the worst-case charge inside a transaction with a `SELECT … FOR UPDATE` on the
  account — concurrent submissions cannot overdraw; insufficient balance aborts atomically with a
  friendly error.
- Mock purchases (`/credits/purchase`, $1–$1 000) credit the balance and write a purchase row.
- Successful generations settle to the exact charge (refund or top-up of the hold difference in one
  `settle` row); failed generations release the hold — the customer never pays for failures.

## 17. Admin reporting

`/admin` (Gate `admin`, `is_admin` flag) shows platform totals, users, recent generations and the
webhook delivery audit. `/admin/financial` provides:

- **Daily P&L** — generations, provider cost, revenue (customer charges), profit, grouped by
  UTC completion day, with date filters. Only *succeeded* generations count.
- **Per-user** — spend, provider cost and profit per user.
- **Per-generation** — the full priced list with token counts.
- **Reconciliation** — the "Reconcile with GET /v1/usage" button (and the daily 03:07 schedule)
  compares our `provider_usage_records` against the provider's own per-day report: cost delta and
  token-level deltas per day, so any missing/unmatched/duplicate record is visible immediately.

## 18. Security considerations

- **Webhook** — the only unauthenticated route; HMAC-SHA256 signature verification on the raw body,
  constant-time compare, replay window ±300 s, per-IP rate limit (120/min), full delivery audit,
  non-2xx on processing errors so the provider retries.
- **Money** — integer nano-dollars end to end; no floats, no silent rounding.
- **Authorization** — per-user data isolation enforced in queries *and* policies (cross-user
  generation access → 403); admin routes gated by the `admin` Gate.
- **CSRF** — all state-changing web routes use Laravel's middleware (the Inertia CSRF token);
  the API webhook route is exempt by design (the signature is the authentication).
- **Validation** — every request validated server-side (`prompt`, `size`, `quality`,
  `reference_images`, purchase amounts, date filters).
- **Secrets** — live only in the server's `.env` (mode 640, site-user only); never in the repo,
  logs or error responses. Rotate the webhook secret from the Pixelforge dashboard if it is ever
  exposed.
- **Database** — dedicated non-superuser role; tests run against SQLite `:memory:`; destructive
  commands (`migrate:fresh`, `db:wipe`) are dev/test-only.
- **Production hygiene** — `APP_DEBUG=false`, HTTPS + HSTS, `Secure`/`HttpOnly`/`SameSite` cookies,
  images stored under unguessable UUID paths, generated images downloaded locally so the app does not
  depend on the provider's 24 h signed URLs.

---

## Deployment

See [`deploy/DEPLOYMENT.md`](deploy/DEPLOYMENT.md) for the production layout (CloudPanel/Ubuntu,
dedicated database + PHP-FPM pool + `systemd` worker + per-user cron) and the
[`deploy/promptedit-worker.service`](deploy/promptedit-worker.service) unit.

## Repository

- **GitHub:** https://github.com/SultanBayezid/promptedit
- **Live app:** https://promptedit.lastlineweb.com
- Stack: Laravel 13 · PHP 8.4 · Vue 3 · Inertia 2 · Tailwind CSS · PostgreSQL 16
