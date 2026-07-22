<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Models\WhatsappMessage;
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

        WhatsappMessage::create([
            'chat_id' => $chatId,
            'direction' => 'in',
            'message' => $text,
            'payload' => $request->all(),
        ]);

        ProcessIncomingWhatsappMessage::dispatch($chatId, $text);

        return response()->json(['status' => 'queued']);
    }
}
