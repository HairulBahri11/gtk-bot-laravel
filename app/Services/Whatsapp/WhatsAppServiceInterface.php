<?php

namespace App\Services\Whatsapp;

/**
 * Kontrak Service Pattern integrasi WhatsApp (§4 PRD). Implementasi saat ini
 * memakai WAHA (Fase 1). Migrasi ke WhatsApp Official API Meta (Fase 2)
 * cukup dengan membuat implementasi baru dan mengganti binding di
 * AppServiceProvider - logika AI, database, dan dashboard tidak berubah.
 */
interface WhatsAppServiceInterface
{
    /**
     * @return bool true kalau gateway benar-benar menerima pesannya. Sebagian
     *              besar caller sengaja MENGABAIKAN nilai balik ini (best-effort, sama
     *              seperti sebelumnya - lihat WahaWhatsAppService::post()), TAPI job
     *              yang pengirimannya retry-safe (mis. NotifyQueueStatusJob/
     *              NotifyShiftChangeJob - kirim ulang isinya tetap konsisten/tidak
     *              berbahaya) WAJIB memeriksa nilai ini & melempar exception kalau
     *              false, supaya gangguan sesaat di gateway WA (WAHA restart/logout
     *              sesi/rate-limit) otomatis di-retry oleh queue worker alih-alih
     *              pesan hilang tanpa jejak.
     */
    public function sendText(string $to, string $message): bool;

    /**
     * Tandai pesan masuk dari chat ini sudah dibaca (centang biru) - dipanggil
     * begitu job mulai memproses pesan, supaya perilaku bot terlihat seperti
     * orang yang benar-benar membaca chat, bukan auto-reply mentah yang lebih
     * mudah dicurigai/diblokir WhatsApp.
     */
    public function sendSeen(string $chatId): void;

    /**
     * Tampilkan indikator "mengetik..." di chat ini. Dipasangkan dengan
     * stopTyping() setelah balasan terkirim.
     */
    public function startTyping(string $chatId): void;

    public function stopTyping(string $chatId): void;
}
