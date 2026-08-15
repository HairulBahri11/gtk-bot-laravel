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
    public function sendText(string $to, string $message): void;

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
