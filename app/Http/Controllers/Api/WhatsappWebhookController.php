<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessIncomingDoctorMessage;
use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Models\Doctor;
use App\Models\WhatsappMessage;
use App\Support\IndonesianPhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbound WAHA webhook (§4.1 PRD): POST /api/whatsapp/webhook.
 */
class WhatsappWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $event = $request->input('event');
        $payload = (array) $request->input('payload', []);

        if ($event !== 'message' || ($payload['fromMe'] ?? false)) {
            return response()->json(['status' => 'ignored']);
        }

        $chatId = $payload['from'] ?? null;
        $text = trim((string) ($payload['body'] ?? ''));

        if (! $chatId || $text === '') {
            return response()->json(['status' => 'ignored']);
        }

        // WAHA diketahui kadang mengirim event webhook yang sama lebih dari
        // sekali (mis. retry karena respons lambat) - tanpa dedup ini, satu
        // pesan user yang sama akan diproses AI & (kalau di STATE_2) memicu
        // booking dua kali secara independen. Kolom wa_message_id unik jadi
        // penjaga utama - exists() check di bawah cuma optimisasi supaya
        // request duplikat tidak perlu menunggu exception DB.
        $waMessageId = $payload['id'] ?? null;

        if ($waMessageId && WhatsappMessage::where('wa_message_id', $waMessageId)->exists()) {
            return response()->json(['status' => 'duplicate']);
        }

        try {
            WhatsappMessage::create([
                'chat_id' => $chatId,
                'wa_message_id' => $waMessageId,
                'direction' => 'in',
                'message' => $text,
                'payload' => $request->all(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['status' => 'duplicate']);
        }

        // Bedakan nomor dokter dari calon pasien SEBELUM masuk ke state
        // machine pasien sama sekali - dokter aktif yang terdaftar di
        // doctors.no_hp diarahkan ke alur perintah terpisah (batalkan/delay
        // shift), bukan pernah menyentuh ChatSession/AiEngineService. Nomor
        // yang tidak cocok tetap diproses seperti sebelumnya, tanpa
        // perubahan sama sekali.
        $phone = IndonesianPhoneNumber::fromChatId($chatId);
        $doctor = $phone ? Doctor::query()->where('no_hp', $phone)->where('is_active', true)->first() : null;

        if ($doctor) {
            ProcessIncomingDoctorMessage::dispatch($chatId, $text, $doctor->kode_dokter);
        } else {
            ProcessIncomingWhatsappMessage::dispatch($chatId, $text);
        }

        return response()->json(['status' => 'queued']);
    }
}
