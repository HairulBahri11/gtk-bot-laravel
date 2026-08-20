<?php

namespace App\Http\Controllers;

use App\Models\Poliklinik;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master data poliklinik - dikelola murni dari dashboard ini. GTK
 * ?url=poliklinik tidak pernah dipanggil lagi sama sekali (lihat
 * QuotaService::syncFromGtk()), jadi kode_poliklinik yang dibuat di sini
 * WAJIB sama persis dengan kode yang dikenal SIMRS Khanza - booking &
 * kunjungan tetap dikirim ke GTK memakai kode ini.
 */
class PoliklinikController extends Controller
{
    public function index(Request $request): Response
    {
        abort_if($request->user()->isDokter(), 403);

        $poliklinik = Poliklinik::query()
            ->orderBy('nama_poliklinik')
            ->get(['kode_poliklinik', 'nama_poliklinik', 'is_active']);

        return Inertia::render('Poliklinik/Index', [
            'poliklinik' => $poliklinik,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if($request->user()->isDokter(), 403);

        $data = $request->validate([
            'kode_poliklinik' => ['required', 'string', 'max:20', 'unique:poliklinik,kode_poliklinik'],
            'nama_poliklinik' => ['required', 'string', 'max:255'],
        ]);

        Poliklinik::create([
            'kode_poliklinik' => $data['kode_poliklinik'],
            'nama_poliklinik' => $data['nama_poliklinik'],
            'is_active' => true,
        ]);

        return back()->with('success', 'Poliklinik ditambahkan.');
    }

    public function update(Request $request, Poliklinik $poliklinik): RedirectResponse
    {
        abort_if($request->user()->isDokter(), 403);

        $data = $request->validate([
            'nama_poliklinik' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);

        $poliklinik->update($data);

        return back()->with('success', 'Poliklinik diperbarui.');
    }
}
