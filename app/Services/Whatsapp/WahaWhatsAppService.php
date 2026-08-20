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
    public function sendText(string $to, string $message): bool
    {
        return $this->post('/api/sendText', [
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
     * Gagal HANYA di-log di sini, tidak pernah melempar exception - caller
     * yang menentukan sendiri apakah kegagalan ini boleh diabaikan
     * (kosmetik, mis. sendSeen/startTyping/stopTyping - TIDAK ADA caller
     * yang memeriksa nilai baliknya) atau harus ditindaklanjuti (mis.
     * sendText dari job yang retry-safe - lihat docblock
     * WhatsAppServiceInterface::sendText()).
     *
     * @param  array<string, mixed>  $payload
     */
    private function post(string $endpoint, array $payload, string $label): bool
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

        return $response->successful();
    }
}
