<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use App\Support\IndonesianPhoneNumber;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Monitoring pasien": daftar chat_session + status pipeline + transkrip
 * percakapan (§2 PRD - dashboard admin/dokter).
 */
class MonitoringController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->input('search');

        $sessions = ChatSession::query()
            ->with(['patient', 'bookings' => fn ($q) => $q->with(['patient', 'poliklinik'])->latest()])
            ->when($search, function ($q) use ($search) {
                $q->where('chat_id', 'like', "%{$search}%")
                    ->orWhere('no_rm', 'like', "%{$search}%")
                    ->orWhereJsonContains('context->nama', $search)
                    ->orWhereHas('bookings.patient', fn ($p) => $p->where('nama', 'like', "%{$search}%"));
            })
            ->orderByDesc('last_message_at')
            ->paginate(20)
            ->withQueryString()
            ->through(function (ChatSession $s) {
                // Satu nomor WA (chat_id) bisa dipakai daftarin lebih dari satu
                // anak dari waktu ke waktu - tampilkan SEMUA pasien yang sudah
                // pernah booking dari sesi ini, jangan cuma booking terakhir.
                $patients = $s->bookings->map(fn ($b) => [
                    'no_rm' => $b->no_rm,
                    'nama' => $b->patient?->nama,
                    'poliklinik' => $b->poliklinik?->nama_poliklinik,
                    'tanggal_periksa' => $b->tanggal_periksa->toDateString(),
                    'status' => $b->status->value,
                ]);

                // Pasien yang sedang diproses (sudah punya no_rm tapi belum
                // sempat booking, mis. masih STATE_2 sebelum konfirmasi) tetap
                // ditampilkan supaya pipeline-nya kelihatan di monitoring.
                if ($s->no_rm && ! $patients->contains('no_rm', $s->no_rm)) {
                    $patients->push([
                        'no_rm' => $s->no_rm,
                        'nama' => $s->context['nama'] ?? $s->patient?->nama,
                        'poliklinik' => $s->context['poli_pilihan'] ?? null,
                        'tanggal_periksa' => null,
                        'status' => 'in_progress',
                    ]);
                }

                return [
                    'id' => $s->id,
                    'chat_id' => $s->chat_id,
                    // chat_id WAHA bisa berformat "...@lid" (kontak yang
                    // menyembunyikan nomor asli) - jangan pernah tampilkan
                    // ID mentah itu sebagai nomor WA, tampilkan null supaya
                    // frontend bisa render fallback yang jelas.
                    'nomor_wa' => IndonesianPhoneNumber::normalize($s->context['no_hp'] ?? null)
                        ?? IndonesianPhoneNumber::fromChatId($s->chat_id),
                    'state' => $s->state->value,
                    'step' => $s->step,
                    'last_message_at' => $s->last_message_at?->toDateTimeString(),
                    'patients' => $patients->values(),
                ];
            });

        return Inertia::render('Monitoring/Index', [
            'sessions' => $sessions,
            'filters' => ['search' => $search],
        ]);
    }

    public function show(ChatSession $chatSession): Response
    {
        $chatSession->load(['patient', 'bookings.doctor', 'bookings.poliklinik', 'bookings.patient', 'messages' => fn ($q) => $q->orderBy('created_at')]);

        return Inertia::render('Monitoring/Show', [
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
