# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

Laravel 12 + Inertia/React app for **Graha Tumbuh Kembang Anak (GTK) Jombang**, a children's clinic. Two independent surfaces share one codebase:

1. **WhatsApp bot backend** (`routes/api.php` → `WhatsappWebhookController`) — patients register and book appointments via WhatsApp; a separate, LLM-free channel lets doctors cancel/delay their own shifts via WhatsApp commands.
2. **Admin dashboard** ("Pre-Layanan" module, `routes/web.php`, Inertia + React) — staff manage doctor schedules, quota, the queue ("Antrean"), and can inspect chat transcripts.

The clinic's actual patient/appointment records live in an external hospital system, **SIMRS Khanza**, reached through a bridging REST API referred to as "GTK API" throughout the code (`App\Services\Gtk\GtkApiService`). Most local database tables are a **cache** of that external system, not the source of truth — see Architecture below.

## Commands

```bash
composer setup     # install deps, copy .env, key:generate, migrate, npm install/build
composer dev        # php artisan serve + queue:listen + pail (logs) + vite, concurrently
php artisan serve
php artisan queue:listen --tries=1 --timeout=0   # WhatsApp jobs run on the queue - nothing processes without a listener
npm run dev          # Vite dev server for the dashboard (Inertia/React)
```

Tests (PHPUnit, not Pest):
```bash
composer test                                                  # config:clear then php artisan test
vendor/bin/phpunit                                              # full suite (Unit + Feature)
vendor/bin/phpunit tests/Feature/WhatsappWebhookTest.php
vendor/bin/phpunit --filter test_full_registration_and_booking_flow
```
Feature tests need a real database — `phpunit.xml` hardcodes `DB_CONNECTION=mysql` + `DB_DATABASE=bot-gtk_test` regardless of `.env`, and there is no committed `.env.testing`, so a local MySQL/MariaDB with that database (and `APP_KEY` set) must be reachable or tests fail immediately. `tests/Unit/*` written against plain `PHPUnit\Framework\TestCase` (not `Tests\TestCase`) skip booting the framework entirely and need no database — prefer that style for pure logic (see `NameSimilarity`, `PatientMatcher`, `DoctorCommandParser`).

Lint/format:
```bash
vendor/bin/pint          # Laravel Pint, default ruleset (no pint.json)
vendor/bin/pint --test   # check only
```

Domain-specific artisan commands (see `routes/console.php` for the live schedule):
```bash
php artisan gtk:sync-quota                     # every 10 min - refreshes quota_shifts cache from GTK
php artisan gtk:sync-kunjungan-reminder        # every 15 min - pulls upcoming visits from GTK into Supabase
php artisan gtk:dispatch-kunjungan-reminder    # every 5 min - evaluates H-1/H-3h/H-1h windows, sends WA
php artisan gtk:process-no-show                # every 15 min
```
`gtk:send-reminders` is the **old** reminder command and is intentionally left out of the schedule — it was replaced by the sync/dispatch pair above to avoid sending duplicate reminders from two systems. Don't re-enable it without removing the new pair.

## Architecture

### Two databases, one app
The Eloquent default connection follows `DB_CONNECTION` — production (see `DEPLOYMENT.md`) points it at Supabase Postgres; local dev/tests typically use MySQL/MariaDB instead. **Two commands are the exception**: `SyncKunjunganReminder` and `DispatchKunjunganReminder` explicitly call `DB::connection('pgsql')` and read/write a `kunjungan_reminder` table that only exists in Supabase (`supabase/migrations/`, separate from `database/migrations/`). Because `config/database.php`'s `pgsql` block reads the *same* generic `DB_HOST`/`DB_PORT`/etc. as every other connection, these two commands only work locally if `.env`'s `DB_*` values actually point at a real Postgres instance — independent of whatever the app's main `DB_CONNECTION` is set to.

### Patient WhatsApp booking flow
Entry: `WhatsappWebhookController` dedupes on `wa_message_id`, then splits by sender — numbers matching an active `doctors.no_hp` go to `ProcessIncomingDoctorMessage`; everyone else goes to `ProcessIncomingWhatsappMessage`, the state-machine orchestrator (a queued job, one per inbound message, serialized per `chat_id` via `WithoutOverlapping`).

- **State machine** (`ChatSession.state` / `App\Enums\ChatState`): `STATE_1_PENGUMPULAN_DATA` (collect nama/tanggal_lahir/nama_ibu_kandung/jenis_kelamin/no_hp/keluhan, classify poliklinik, resolve patient identity) → `STATE_2_KONFIRMASI` (pick poli/shift/tanggal, confirm, create a `Booking`) → `STATE_3_DONE` (cancel / reschedule / start a new visit for the same patient, loops back to STATE_1).
- **`AiEngineService`** is pure NLU: one LLM call (Gemini via OpenRouter) per message, returns `{reply, extracted, ready_for_next_state}`. It never queries the database or calls GTK. Its system prompt is assembled per-state (`stateOnePrompt`/`stateTwoPrompt`/`stateThreePrompt`) and always includes the full `chat_sessions.context` JSON blob so the model sees prior turns' already-extracted data.
- **Never trust the AI's gate signals alone.** Anywhere the AI can set `ready_for_next_state`/`extracted.konfirmasi` to true, the Job re-validates server-side using its own flags persisted in `context` (`poli_disetujui`, `no_hp_dikonfirmasi`, `tanggal_kunjungan_dijawab`, `slot_ditawarkan`, `pasien_ditawarkan`). The recurring pattern is "offer something concrete on turn N (server-composed message, not the AI's), only commit if the *same* offer is still pending and confirmed on turn N+1" — used for booking slots (`handleStateTwo`) and for patient-identity matches (`handleProbableMatch`). Follow this pattern for any new step that commits something consequential based on an LLM-interpreted "yes".
- **Patient identity resolution** (`resolvePatient()` in the Job + `App\Services\Patient\PatientMatcher`): GTK's `cariPasien` search is an external black box that may not tolerate typos, so every candidate it returns is scored — not just the first — with `tanggal_lahir` as a hard exact-match gate, `nama` as the fuzzy signal (`App\Support\NameSimilarity`), and `nama_ibu_kandung`/`no_hp` only ever able to promote a weak name match to "ask the user to confirm", never silently to "use it" (deliberate: twins share DOB and mother's name). Falls back to scoring the local `patients` cache (by `tanggal_lahir`) only when GTK's own candidates aren't confident enough — the call made to GTK's `cariPasien` itself is intentionally never changed/widened, since its actual search behavior is unverified.
- `bookings.no_rm` → `patients.no_rm` is **not** a real foreign key. `no_rm` originates from GTK (string, not auto-increment), and both tables are kept in sync by hand wherever `PatientMatcher`/GTK calls happen.

### Doctor WhatsApp flow
Fully separate from the above: no LLM, no `ChatSession`. `DoctorCommandParser` recognizes two fixed patterns (cancel / delay a shift) via regex, and `ProcessIncomingDoctorMessage` applies them directly to `QuotaShift`/`DoctorSchedule` and notifies affected patients (`NotifyShiftChangeJob`, staggered sends — `config/gtk.php: notification_stagger_*` — to avoid WA rate limits).

### Quota & booking domain services
`QuotaService` reads/writes the `quota_shifts` cache (synced from GTK periodically via `gtk:sync-quota`, never queried live per-chat) and is the single source of truth for shift availability. `AntreanService` creates bookings/waitlist entries, confirms arrivals, and handles no-show buffer shifting (`config/gtk.php: no_show_buffer_min/max`). Both are shared by the bot job and the dashboard (`AntreanController`, `KuotaController`, `JadwalDokterController`).

### Reminders — two independent pipelines, don't conflate them
- `ReminderLog` (local, tied to `Booking`) — legacy, driven by the now-disabled `gtk:send-reminders`.
- `kunjungan_reminder` (Supabase-only, see "Two databases" above) — the live pipeline: `gtk:sync-kunjungan-reminder` pulls visit data from GTK, `gtk:dispatch-kunjungan-reminder` evaluates the H-1 day / H-3h / H-1h windows and sends the WhatsApp messages.

### External integrations (`config/services.php`)
- `waha` — self-hosted WhatsApp gateway; inbound webhook + outbound send go through `WhatsAppServiceInterface` (bound to `WahaWhatsAppService` in `AppServiceProvider` — swap the binding there, not call sites, if the WA provider ever changes).
- `gtk` — SIMRS Khanza bridging API (`GtkApiService`). Auth token cached ~50 min (`config/gtk.php: token_cache_*`). Non-standard routing: endpoints are passed as `?url=<endpoint>`, not REST paths. Failures surface as `GtkApiException` carrying GTK's own status code — `isNotFound()`/`isDuplicate()`/`isValidationError()`/`isSystemError()` map 404/409/201/401 respectively.
- `openrouter` — LLM calls, used only for patient NLU (`AiEngineService`); doctor commands and doctor-schedule FAQ answers are answered deterministically without ever calling the LLM.

### Deployment
Docker + Traefik on a shared VPS (full runbook in `DEPLOYMENT.md`) — one image, three containers per environment (`app` = nginx+php-fpm, `worker` = `queue:work database`, `scheduler` = looped `schedule:run`), joined to a pre-existing external `n8n_web` Traefik network. Production and a parallel dev/staging stack can run side-by-side on the same VPS via `APP_ENV_TAG`/`APP_DOMAIN`. Deploys are `git pull` + `docker compose build` via `./deploy/deploy.sh`, not CI/CD — there is no GitHub Actions pipeline in this repo.
