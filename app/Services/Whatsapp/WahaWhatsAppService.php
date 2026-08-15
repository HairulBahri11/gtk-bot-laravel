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
        $this->post('/api/sendText', [
            'chatId' => $to,
            'text' => $message,
        ], 'sendText');
    }

    public function sendSeen(string $chatId): void
    {
        $this->post('/api/sendSeen', [
            'chatId' => $chatId,
        ], 'sendSeen');
    }

    public function startTyping(string $chatId): void
    {
        $this->post('/api/startTyping', [
            'chatId' => $chatId,
        ], 'startTyping');
    }

    public function stopTyping(string $chatId): void
    {
        $this->post('/api/stopTyping', [
            'chatId' => $chatId,
        ], 'stopTyping');
    }

    /**
     * sendSeen/startTyping/stopTyping sengaja best-effort (gagal cuma
     * di-log, sama seperti sendText) - endpoint kosmetik seperti ini tidak
     * boleh pernah menggagalkan/menghentikan alur balasan sesungguhnya.
     *
     * @param  array<string, mixed>  $payload
     */
    private function post(string $endpoint, array $payload, string $label): void
    {
        $response = Http::baseUrl(config('services.waha.url'))
            ->withHeaders(array_filter([
                'X-Api-Key' => config('services.waha.api_key'),
            ]))
            ->post($endpoint, [
                'session' => config('services.waha.session'),
                ...$payload,
            ]);

        if ($response->failed()) {
            Log::error("WAHA {$label} gagal", [
                'payload' => $payload,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }
}
