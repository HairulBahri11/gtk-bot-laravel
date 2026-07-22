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
}
