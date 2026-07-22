<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Implementasi Fase 1 (§4.1 PRD) - outbound message ditembak ke
 * POST {waha_url}/api/sendText.
 */
class WahaWhatsAppService implements WhatsAppServiceInterface
{
    public function sendText(string $to, string $message): void
    {
        $response = Http::baseUrl(config('services.waha.url'))
            ->withHeaders(array_filter([
                'X-Api-Key' => config('services.waha.api_key'),
            ]))
            ->post('/api/sendText', [
                'session' => config('services.waha.session'),
                'chatId' => $to,
                'text' => $message,
            ]);

        if ($response->failed()) {
            Log::error('WAHA sendText gagal', [
                'to' => $to,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }
}
