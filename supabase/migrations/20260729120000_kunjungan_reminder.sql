-- Sistem reminder kunjungan (H-1 hari, H-3 jam, H-1 jam). Sync (GTK API ->
-- tabel ini) & dispatch (evaluasi window + kirim WA) sama-sama berjalan
-- sebagai scheduled command Laravel (app/Console/Commands/
-- SyncKunjunganReminder.php & DispatchKunjunganReminder.php) yang menulis
-- ke tabel ini lewat koneksi 'pgsql'. Tabel ini sengaja TIDAK didaftarkan
-- sebagai migration Laravel biasa supaya tidak ikut ter-drop/ter-migrate
-- ulang oleh `php artisan migrate` di koneksi lain (mis. mysql lokal saat
-- testing) - hanya dijalankan SEKALI secara manual di Supabase.
--
-- Jalankan file ini via Supabase SQL Editor (atau sudah otomatis dijalankan
-- lewat koneksi pgsql Laravel saat setup awal).

create table if not exists kunjungan_reminder (
    no_rawat            text primary key,
    nama_pasien         text not null,
    nohp_raw            text,
    nohp                text,                 -- ternormalisasi ke format 62xxxxxxxxxx
    kodepoli            text,
    poli                text,
    dokter              text,
    tanggal_kunjungan   date not null,
    jam_kunjungan       time not null,
    jadwal_at           timestamp generated always as (tanggal_kunjungan + jam_kunjungan) stored,

    status_kunjungan    text not null default 'active',   -- active | cancelled | expired

    reminder_h1hari_status  text not null default 'pending',  -- pending | sent | failed | skipped
    reminder_h1hari_sent_at timestamptz,

    reminder_h3jam_status   text not null default 'pending',
    reminder_h3jam_sent_at  timestamptz,

    reminder_h1jam_status   text not null default 'pending',
    reminder_h1jam_sent_at  timestamptz,

    last_synced_at      timestamptz not null default now(),
    created_at           timestamptz not null default now(),
    updated_at           timestamptz not null default now()
);

create index if not exists idx_kr_jadwal on kunjungan_reminder (jadwal_at);
create index if not exists idx_kr_pending_h1hari on kunjungan_reminder (jadwal_at) where reminder_h1hari_status = 'pending';
create index if not exists idx_kr_pending_h3jam  on kunjungan_reminder (jadwal_at) where reminder_h3jam_status  = 'pending';
create index if not exists idx_kr_pending_h1jam  on kunjungan_reminder (jadwal_at) where reminder_h1jam_status  = 'pending';

create table if not exists reminder_log (
    id                bigint generated always as identity primary key,
    no_rawat          text not null references kunjungan_reminder(no_rawat) on delete cascade,
    jenis_reminder    text not null,        -- h1hari | h3jam | h1jam
    attempt_at        timestamptz not null default now(),
    status            text not null,        -- success | failed
    gateway_response  jsonb,
    error_message     text
);

create index if not exists idx_rl_no_rawat on reminder_log (no_rawat);

-- updated_at otomatis, dipakai sync job saat upsert.
create or replace function set_updated_at()
returns trigger as $$
begin
    new.updated_at = now();
    return new;
end;
$$ language plpgsql;

drop trigger if exists trg_kunjungan_reminder_updated_at on kunjungan_reminder;
create trigger trg_kunjungan_reminder_updated_at
    before update on kunjungan_reminder
    for each row
    execute function set_updated_at();
