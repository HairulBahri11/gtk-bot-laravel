# Prompt untuk Claude Code — Revisi Jadwal Dinamis Dokter (Poli Spesialis Anak)

Paste seluruh isi di bawah ini sebagai prompt awal ke Claude Code di dalam repo `gtk-bot-laravel`.

---

Aku mau implementasi revisi berikut untuk project GTK (Griya Tumbuh Kembang), berdasarkan diskusi tim tanggal 9–11 Agustus 2026. Sebelum coding, pahami dulu arsitektur yang sudah ada — jangan berasumsi, baca kode aslinya:

**Konteks arsitektur yang sudah ada (WAJIB dibaca dulu sebelum ubah apa pun):**
- `app/Jobs/ProcessIncomingWhatsappMessage.php` — orkestrator utama: setiap pesan WA masuk (dari `WhatsappWebhookController`) diproses lewat state machine 3 tahap (`AiEngineService` + `ChatState`), SEMUA nomor pengirim diperlakukan sama sebagai calon pasien. Tidak ada pembedaan nomor dokter vs pasien sama sekali saat ini.
- `app/Services/Quota/QuotaService.php` — `doctor_schedules` dan `quota_shifts` SAAT INI disinkron otomatis dari API GTK (`GtkApiService::jadwalDokter()`/`dokterAktif()`) lewat `syncFromGtk()`, dijalankan command `gtk:sync-quota`. `doctor_schedules` juga dipakai LANGSUNG oleh mesin pencarian slot booking pasien (`ProcessIncomingWhatsappMessage::findNearestSlot()` / `findSlotOnDate()`).
- `app/Models/Doctor.php` — belum ada kolom nomor HP sama sekali.
- `app/Models/User.php` — dashboard sudah punya role `dokter` + kolom `kode_dokter` (`isDokter()`), dan `KuotaController`/`AntreanController` sudah scope data by dokter yang login — tapi ini LOGIN DASHBOARD, beda mekanisme dari nomor WA yang chat ke AI.
- `app/Services/Ai/AiEngineService.php` — sistem klasifikasi keluhan SUDAH mencakup 9 poliklinik (Poli Spesialis Anak, Gizi, Poli DDTK, Fisioterapi, Terapi Wicara, Terapi Okupasi, Psikolog, Baby Spa, Poli Khitan), BUKAN cuma "Poli Anak" seperti sempat dibahas di chat tim — kemungkinan sudah berkembang sejak diskusi itu. **Jangan mempersempit ini lagi, di luar scope revisi.**
- `app/Models/Booking.php` + `App\Enums\BookingStatus` sudah punya status `rescheduled`/`cancelled`/`waitlist`/`no_show`, kolom `buffer_shifted_count`, `cancel_reason`.
- `app/Services/Antrean/AntreanService.php` sudah punya pola geser-antrean (`markNoShow()`, `promoteWaitlist()`, `shiftBuffer()`, `reserveSlot()`/`releaseSlot()`, termasuk `random_int()` untuk buffer) — pakai ini sebagai referensi pola, jangan bikin logic geser jadwal dari nol.
- `app/Console/Commands/SendAppointmentReminders.php` — pola job pengingat idempotent via tabel `reminder_logs`, kirim WA lewat `WhatsAppServiceInterface::sendText()`.
- `KuotaController`/`AntreanController` (`routes/web.php`, prefix `/pre-layanan`) — dashboard SAAT INI cuma baca data (tombol "Tampilkan" + "Sinkronkan dari GTK"), tidak ada CRUD/manage jadwal sama sekali.
- DB-nya Postgres yang di-host di Supabase, diakses lewat Eloquent/migration Laravel biasa (BUKAN Supabase client SDK) — jadi "bikin tabel di Supabase" = migration Laravel biasa.

## Yang perlu diimplementasikan

### 1. Jadwal manual dr. RA Retno Wulandari, SpA (Poli Spesialis Anak) — bukan sync dari GTK
Alasan: endpoint GTK `/jadwaldokter` untuk dokter ini tidak terisi/tidak dipakai di sisi GTK.
- Jadwal tetap: Senin–Jumat pagi 08.00–09.30, sore 15.30–17.00, malam 18.30–20.00; Sabtu pagi 08.00–11.00, sore 15.30–17.00.
- Pastikan baris jadwal dokter ini TIDAK ikut ditimpa `QuotaService::syncSchedules()`. Pertimbangkan extend `doctor_schedules` dengan kolom `source` (`gtk`/`manual`) daripada bikin tabel paralel baru — karena mesin booking query langsung dari `doctor_schedules`. Kalau tetap pilih tabel terpisah, mesin booking (`findNearestSlot`/`findSlotOnDate`) wajib disesuaikan juga supaya tidak pecah.

### 2. Status harian per shift: batal / delay
Ini aksi PER TANGGAL, bukan ubah jadwal template mingguan — taruh di level instance harian (`quota_shifts` atau tabel override baru per tanggal+shift+dokter), bukan di `doctor_schedules`.
- Kolom baru: `status` (`open`/`cancelled`/`delayed`), `delay_minutes`, `reason`.
- Method baru di service terkait, mis. `cancelShift()` dan `delayShift()`.

### 3. Label nomor WhatsApp: dokter vs pasien
- Tambah kolom nomor HP ke `doctors`, ATAU manfaatkan `users.kode_dokter` yang sudah ada sebagai sumber pemetaan nomor→dokter. Pilih satu sumber kebenaran, jangan duplikasi.
- Di awal `ProcessIncomingWhatsappMessage::handle()` (atau di webhook), cek nomor pengirim: kalau cocok nomor dokter aktif, JANGAN masuk ke alur `AiEngineService` pasien — arahkan ke handler perintah dokter (poin 4). Kalau tidak cocok, alur pasien tetap seperti sekarang, tidak berubah.

### 4. Dua perintah dokter via WA
- Batalkan shift tertentu (hari/tanggal + pagi/sore/malam).
- Lapor delay (mis. telat 30 menit).
- Pasien pakai AI Gemini natural-language karena butuh fleksibilitas; untuk perintah dokter boleh pakai parser lebih sederhana/terstruktur supaya tidak mencampur system prompt pasien yang sudah kompleks. Tetap minta konfirmasi eksplisit sebelum eksekusi (pola sama seperti `STATE_2_KONFIRMASI` di alur pasien).

### 5. Efek berantai (cascading reschedule)
- Saat shift dibatalkan: semua booking `booked`/`confirmed` di kombinasi dokter+tanggal+shift itu otomatis dipindah ke shift berikutnya di hari yang sama (pagi→sore→malam), geser berantai lagi kalau shift berikutnya juga penuh/batal. Set status booking `rescheduled` (enum sudah ada).
- Saat delay: booking tetap di shift yang sama, tapi jam efektif mundur N menit — pastikan reminder & pesan konfirmasi pasien ikut menyesuaikan.

### 6. Notifikasi otomatis dengan delay random per pasien
- Job baru (queued) dipicu setiap `cancelShift()`/`delayShift()` dieksekusi: kirim WA ke tiap pasien terdampak satu per satu dengan jeda random antar pengiriman (pola sama seperti `random_int()` yang sudah dipakai untuk buffer no-show), supaya tidak kena rate-limit/blokir Meta.
- Idempoten: ikuti pola `ReminderLog` supaya job yang retry tidak kirim dobel.

### 7. Dashboard: kelola jadwal (belum ada sama sekali)
- Controller/route baru (pola sama seperti `KuotaController`/`AntreanController` + Inertia): CRUD jadwal mingguan manual dr. Retno, dan set/lihat status harian per shift.
- Tambahkan indikator status shift (buka/dibatalkan/delay) di halaman Kuota yang sudah ada.
- Opsional: tombol admin untuk trigger cancel/delay manual dari dashboard sebagai fallback, reuse service yang sama dari poin 2/4.
- Role `dokter` di dashboard sudah ada dan sudah di-scope di `KuotaController`/`AntreanController` — pertimbangkan dokter yang login dashboard juga bisa kelola jadwalnya sendiri dari situ, bukan cuma via WA.

### 8. Alokasi kuota ganda (periksa sakit vs konsultasi gizi) — JANGAN DITEBAK, TANYA DULU
Dari chat tim: 15 kuota/shift (default 14 periksa sakit + 1 konsultasi gizi, dinamis kalau demand periksa sakit rendah), gizi 45 menit/slot vs sakit 10 menit/slot, gizi hanya shift sore & malam, konsultasi tumbuh kembang dibatasi 1 pasien/hari.

Tapi di kode saat ini "Gizi" dan "Poli DDTK" (kemungkinan = konsultasi tumbuh kembang) sudah jadi **poliklinik terpisah** dengan kode sendiri, bukan sub-alokasi di dalam kuota Poli Spesialis Anak. Sebelum implementasi bagian ini, **stop dan tanya ke aku**: apakah kuota Gizi/DDTK memang harus dilebur jadi sub-alokasi dinamis di dalam kuota shift Poli Spesialis Anak (butuh field baru semacam `jenis_layanan` di `Booking`/`QuotaShift` + logic alokasi dinamis), atau tetap sebagai poliklinik independen seperti sekarang dan cukup disambungkan ke jadwal manual dr. Retno di poin 1 + batas 1 pasien/hari untuk DDTK.

## Yang TIDAK boleh berubah
- Alur booking pasien existing (STATE_1/2/3, klasifikasi 9 poliklinik, integrasi `GtkApiService` untuk `regPasien`/`batalKunjungan`/dst).
- Sinkronisasi GTK untuk dokter/poliklinik lain selain dr. Retno Wulandari tetap jalan seperti biasa.

## Definition of done
- Migration + model/service baru mengikuti konvensi yang sudah ada (Eloquent, enum PHP 8.1 di `app/Enums`, service class per domain di `app/Services/<Domain>`).
- Test baru untuk logic kritis (cascading reschedule minimal) — repo sudah punya `tests/` + `phpunit.xml`, ikuti pola test yang ada.
- Kerjakan poin 1–7 langsung. Poin 8 baru dikerjakan setelah aku jawab pertanyaan klarifikasinya.
