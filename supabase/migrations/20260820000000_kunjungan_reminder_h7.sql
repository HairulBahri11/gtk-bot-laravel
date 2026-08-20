-- Reminder mingguan (H-7/H-14/H-21/dst) untuk kunjungan yang jaraknya JAUH
-- dari tanggal pendaftaran (> 7 hari) - lihat app/Console/Commands/
-- DispatchKunjunganReminder.php untuk logikanya. Berbeda dari
-- reminder_h1hari/h3jam/h1jam (single-shot, kolom status pending/sent/
-- failed/skipped) karena checkpoint H-7 BISA terkirim berkali-kali untuk
-- satu kunjungan yang sama (H-21, lalu H-14, lalu H-7) selama sisa hari ke
-- kunjungan masih kelipatan 7 - jadi disimpan sebagai "kelipatan (hari)
-- terakhir yang sudah terkirim", bukan status tunggal. Sama seperti tabel
-- induknya, dijalankan manual sekali via Supabase SQL Editor (lihat
-- 20260729120000_kunjungan_reminder.sql).

alter table kunjungan_reminder
    add column if not exists reminder_h7_last_multiple_sent integer,
    add column if not exists reminder_h7_last_sent_at timestamptz;

create index if not exists idx_kr_pending_h7 on kunjungan_reminder (tanggal_kunjungan)
    where status_kunjungan = 'active';
