# Deploy gtk-bot-laravel ke VPS (Docker + Traefik, git-based)

Panduan ini menyesuaikan dengan setup VPS yang sudah ada: Docker + Traefik (dipakai n8n di port 80), domain dikelola di Dynadot.

- VPS: `148.230.96.130` (SSH: `ssh -i /path/ke/id_ed25519 root@148.230.96.130`)
- Repo: `https://github.com/HairulBahri11/gtk-bot-laravel.git` (branch `main`)
- Subdomain aplikasi: **dashboard.gtkjombang.com**
- Traefik network yang sudah ada: `n8n_web` (external), entrypoints `web,websecure`, certresolver `mytlschallenge`
- Database: Supabase (PostgreSQL)

Kenapa Docker (bukan native Nginx)? Port 80/443 di VPS sudah dipakai container Traefik milik n8n. Jadi app ini jalan sebagai container baru yang **join network `n8n_web`** dan diarahkan Traefik lewat label, persis seperti pola `gtkjombang-web` yang sudah pernah dipakai.

## Arsitektur container

3 container, satu image (`gtkjombang-dashboard:latest`), beda command:

| Container | Fungsi | Kena Traefik? |
|---|---|---|
| `gtkjombang-dashboard-app` | nginx + php-fpm (web app) | Ya, ini yang di-expose ke `dashboard.gtkjombang.com` |
| `gtkjombang-dashboard-worker` | `queue:work` — proses job WhatsApp masuk & sync kuota | Tidak |
| `gtkjombang-dashboard-scheduler` | loop `schedule:run` tiap 60 detik — reminder & no-show | Tidak |

Session/cache/queue Laravel pakai driver `database` (Supabase), jadi ketiga container tidak perlu shared volume/state.

## 0. Prasyarat

- Domain `gtkjombang.com` sudah terdaftar & dikelola di Dynadot.
- Kredensial Supabase (host, port, database, username, password).
- API key: `WAHA_API_KEY`, `OPENROUTER_API_KEY`, kredensial `GTK_API_*`.

## 1. Buat DNS record subdomain di Dynadot

Di Dynadot → domain `gtkjombang.com` → DNS Settings, tambahkan:

```
Type: A
Subdomain/Host: dashboard
Value: 148.230.96.130
TTL: default
```

Tunggu propagasi (beberapa menit – 1 jam) sebelum Traefik bisa terbitkan SSL. Cek dengan:

```bash
dig +short dashboard.gtkjombang.com
```

## 2. Clone repo di VPS

```bash
ssh -i /path/ke/id_ed25519 root@148.230.96.130

mkdir -p /docker/gtkjombang-dashboard
cd /docker
git clone -b main https://github.com/HairulBahri11/gtk-bot-laravel.git gtkjombang-dashboard
cd gtkjombang-dashboard
```

Kalau repo private, siapkan SSH deploy key dulu (read-only) di GitHub repo → Settings → Deploy keys, sama seperti setup key untuk VPS pada umumnya.

## 3. Konfigurasi `.env`

`.env` **tidak** ikut masuk ke image Docker (ada di `.dockerignore`) — dibuat manual sekali di VPS dan dibaca lewat `env_file` di `docker-compose.yml`.

```bash
cp .env.example .env
nano .env
```

Isi minimal:

```env
APP_NAME="GTK Bot Jombang"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://dashboard.gtkjombang.com
APP_KEY=                      # isi setelah generate di langkah 4

DB_CONNECTION=pgsql
DB_HOST=db.xxxxxxxxxxxx.supabase.co
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres
DB_PASSWORD=isi_password_supabase
DB_SSLMODE=require

QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database

WAHA_URL=https://waha-anda.example.com
WAHA_SESSION=default
WAHA_API_KEY=isi_waha_key

GTK_API_BASE_URL=https://simrs-khanza.example.com
GTK_API_USERNAME=isi_username
GTK_API_PASSWORD=isi_password

OPENROUTER_API_KEY=isi_openrouter_key
OPENROUTER_MODEL=google/gemini-2.5-flash

GTK_NO_SHOW_BUFFER_MIN=3
GTK_NO_SHOW_BUFFER_MAX=5
ADMIN_WHATSAPP_NUMBER=628113106787
```

Catatan Supabase: kalau koneksi langsung (port 5432) sering putus/limit, pakai **Connection Pooling** dari dashboard Supabase (Project Settings → Database → Connection pooling, port `6543`) dan set `DB_PORT=6543`. Kalau Network Restrictions aktif di Supabase, whitelist IP `148.230.96.130`.

Generate `APP_KEY` (butuh PHP, paling gampang lewat container sementara):

```bash
docker run --rm -v "$PWD":/app -w /app php:8.2-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Copy hasilnya ke `APP_KEY=` di `.env`.

## 4. Build & jalankan container

```bash
cd /docker/gtkjombang-dashboard
docker compose build
docker compose run --rm app php artisan migrate --force
docker compose up -d
```

Cek status:

```bash
docker compose ps
docker logs gtkjombang-dashboard-app --tail 50
docker logs gtkjombang-dashboard-worker --tail 50
docker logs gtkjombang-dashboard-scheduler --tail 50
```

Cek Traefik sudah pick up router baru:

```bash
docker logs n8n-traefik-1 --tail 50 | grep -i gtkjombang-dashboard
```

Buka `https://dashboard.gtkjombang.com` — kalau SSL belum keluar, tunggu 1-2 menit (Traefik TLS challenge), pastikan DNS sudah propagasi (langkah 1).

## 5. Update berikutnya (git-based)

Cukup jalankan sekali di VPS:

```bash
cd /docker/gtkjombang-dashboard
./deploy/deploy.sh
```

Script ini otomatis: `git pull origin main` → `docker compose build` → jalankan migrasi lewat container sementara → `docker compose up -d --force-recreate` (app, worker, scheduler dapat image baru) → bersihkan image lama.

## Troubleshooting

- **SSL tidak keluar** → cek `docker logs n8n-traefik-1 --tail 100 | grep -i dashboard.gtkjombang`, pastikan DNS A record sudah benar & propagasi selesai.
- **502 dari Traefik** → cek `docker logs gtkjombang-dashboard-app`, biasanya php-fpm/nginx di dalam container belum siap atau env salah.
- **Job WhatsApp/sync kuota tidak jalan** → `docker logs gtkjombang-dashboard-worker`, pastikan container `restart: always` dan tidak crash-loop (`docker compose ps`).
- **Reminder/no-show tidak jalan** → `docker logs gtkjombang-dashboard-scheduler`.
- **Gagal konek Supabase** → cek `DB_SSLMODE=require`, cek Network Restrictions di Supabase, test dari dalam container: `docker compose run --rm app php artisan tinker` → `DB::connection()->getPdo();`.
- **Container lain (n8n) ikut terganggu** → pastikan network di `docker-compose.yml` tetap `external: true, name: n8n_web`, jangan buat network baru dengan nama sama.
