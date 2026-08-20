<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use App\Support\IndonesianPhoneNumber;
use Illuminate\Http\JsonResponse;

/**
 * "Monitoring pasien": transkrip percakapan WhatsApp per chat session
 * (§2 PRD). Daftar sesi sendiri sudah tidak perlu halaman terpisah - tabel
 * Antrean (AntreanController) sudah menampilkan & mencari pasien yang sama;
 * endpoint ini sekarang murni JSON, dipanggil dari modal transkrip di
 * halaman Antrean, bukan Inertia page sendiri.
 */
class MonitoringController extends Controller
{
    public function show(ChatSession $chatSession): JsonResponse
    {
        $chatSession->load(['patient', 'bookings.doctor', 'bookings.poliklinik', 'bookings.patient', 'messages' => fn ($q) => $q->orderBy('created_at')]);

        return response()->json([
            'session' => [
                'id' => $chatSession->id,
                'chat_id' => $chatSession->chat_id,
                'nomor_wa' => IndonesianPhoneNumber::normalize($chatSession->context['no_hp'] ?? null)
                    ?? IndonesianPhoneNumber::fromChatId($chatSession->chat_id),
                'state' => $chatSession->state->value,
                'context' => $chatSession->context,
                // tanggal_lahir di-cast 'date' - jangan kirim model mentah,
                // serialisasi JSON default Laravel mengonversinya ke UTC dan
                // menggeser tanggal mundur 1 hari untuk timezone +7 (WIB).
                'patient' => $chatSession->patient ? [
                    'no_rm' => $chatSession->patient->no_rm,
                    'nama' => $chatSession->patient->nama,
                    'jk' => $chatSession->patient->jk,
                    'tanggal_lahir' => $chatSession->patient->tanggal_lahir?->toDateString(),
                    'nama_ibu_kandung' => $chatSession->patient->nama_ibu_kandung,
                    'no_hp' => IndonesianPhoneNumber::normalize($chatSession->patient->no_hp),
                ] : null,
                // Satu nomor WA bisa dipakai daftarin lebih dari satu anak -
                // setiap baris riwayat WAJIB bawa identitas pasiennya sendiri
                // (no_rm & nama), bukan cuma poli/dokter/shift, supaya tidak
                // tertukar antar anak saat riwayatnya lebih dari satu.
                'bookings' => $chatSession->bookings->map(fn ($b) => [
                    'id' => $b->id,
                    'no_rawat' => $b->no_rawat,
                    'no_rm' => $b->no_rm,
                    'nama_pasien' => $b->patient?->nama,
                    'poliklinik' => $b->poliklinik?->nama_poliklinik,
                    'dokter' => $b->doctor?->nama_dokter,
                    'tanggal_periksa' => $b->tanggal_periksa->toDateString(),
                    'shift' => $b->shift->value,
                    'status' => $b->status->value,
                ]),
                'messages' => $chatSession->messages->map(fn ($m) => [
                    'direction' => $m->direction,
                    'message' => $m->message,
                    'created_at' => $m->created_at->toDateTimeString(),
                ]),
            ],
        ]);
    }
}
