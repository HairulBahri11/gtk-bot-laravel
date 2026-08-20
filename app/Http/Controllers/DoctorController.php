<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\Poliklinik;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master data dokter - dikelola murni dari dashboard ini. GTK
 * ?url=dokteraktif tidak pernah dipanggil lagi sama sekali (lihat
 * QuotaService), jadi kode_dokter yang dibuat di sini WAJIB sama persis
 * dengan kode yang dikenal SIMRS Khanza - booking & kunjungan tetap dikirim
 * ke GTK memakai kode ini. no_hp WAJIB diisi supaya dokter ini bisa dikenali
 * di jalur WhatsApp dokter (lihat WhatsappWebhookController - nomor pengirim
 * yang cocok ke doctors.no_hp dialihkan ke ProcessIncomingDoctorMessage,
 * bukan alur booking pasien biasa).
 */
class DoctorController extends Controller
{
    public function index(Request $request): Response
    {
        abort_if($request->user()->isDokter(), 403);

        $doctors = Doctor::query()
            ->with('poliklinik')
            ->orderBy('nama_dokter')
            ->get(['kode_dokter', 'nama_dokter', 'no_hp', 'kode_poliklinik', 'is_active']);

        $poliklinik = Poliklinik::query()
            ->where('is_active', true)
            ->orderBy('nama_poliklinik')
            ->get(['kode_poliklinik', 'nama_poliklinik']);

        return Inertia::render('Dokter/Index', [
            'doctors' => $doctors->map(fn (Doctor $d) => [
                'kode_dokter' => $d->kode_dokter,
                'nama_dokter' => $d->nama_dokter,
                'no_hp' => $d->no_hp,
                'kode_poliklinik' => $d->kode_poliklinik,
                'nama_poliklinik' => $d->poliklinik?->nama_poliklinik,
                'is_active' => $d->is_active,
            ]),
            'poliklinik' => $poliklinik,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if($request->user()->isDokter(), 403);

        $data = $request->validate([
            'kode_dokter' => ['required', 'string', 'max:20', 'unique:doctors,kode_dokter'],
            'nama_dokter' => ['required', 'string', 'max:255'],
            'no_hp' => ['nullable', 'string', 'max:20', 'unique:doctors,no_hp'],
            'kode_poliklinik' => ['nullable', 'string', Rule::exists('poliklinik', 'kode_poliklinik')],
        ]);

        Doctor::create([
            'kode_dokter' => $data['kode_dokter'],
            'nama_dokter' => $data['nama_dokter'],
            'no_hp' => $data['no_hp'] ?: null,
            'kode_poliklinik' => $data['kode_poliklinik'] ?: null,
            'is_active' => true,
        ]);

        return back()->with('success', 'Dokter ditambahkan.');
    }

    public function update(Request $request, Doctor $doctor): RedirectResponse
    {
        abort_if($request->user()->isDokter(), 403);

        $data = $request->validate([
            'nama_dokter' => ['required', 'string', 'max:255'],
            'no_hp' => ['nullable', 'string', 'max:20', Rule::unique('doctors', 'no_hp')->ignore($doctor->kode_dokter, 'kode_dokter')],
            'kode_poliklinik' => ['nullable', 'string', Rule::exists('poliklinik', 'kode_poliklinik')],
            'is_active' => ['required', 'boolean'],
        ]);

        $doctor->update([
            'nama_dokter' => $data['nama_dokter'],
            'no_hp' => $data['no_hp'] ?: null,
            'kode_poliklinik' => $data['kode_poliklinik'] ?: null,
            'is_active' => $data['is_active'],
        ]);

        return back()->with('success', 'Dokter diperbarui.');
    }
}
