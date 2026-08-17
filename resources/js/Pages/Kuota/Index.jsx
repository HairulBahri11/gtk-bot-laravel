import PreLayananLayout from '@/Layouts/PreLayananLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import ShiftMeter from '@/Components/ShiftMeter';
import { Head, router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

const shiftLabel = { pagi: 'Pagi', sore: 'Sore', malam: 'Malam' };
const shiftOrder = ['pagi', 'sore', 'malam'];

// Antrean di halaman ini murni tampilan (auto-refresh) - tidak ada
// websocket/Echo di codebase ini, jadi "otomatis muncul & berurutan"
// diwujudkan lewat polling ringan tiap 15 detik, bukan push realtime.
const ANTREAN_POLL_INTERVAL_MS = 15000;

export default function KuotaIndex({ quotaShifts, antrean, doctors, filters }) {
    const [tanggal, setTanggal] = useState(filters.tanggal);
    const [dokter, setDokter] = useState(filters.dokter ?? '');

    useEffect(() => {
        const interval = setInterval(() => {
            router.reload({
                only: ['antrean', 'quotaShifts'],
                preserveScroll: true,
                preserveState: true,
            });
        }, ANTREAN_POLL_INTERVAL_MS);

        return () => clearInterval(interval);
    }, []);

    const shiftTotals = useMemo(
        () =>
            shiftOrder.map((shift) => {
                const rows = quotaShifts.filter((q) => q.shift === shift);

                return {
                    shift,
                    label: shiftLabel[shift],
                    total: rows.reduce((sum, r) => sum + r.kuota_total, 0),
                    used: rows.reduce((sum, r) => sum + r.kuota_terpakai, 0),
                    totalPemeriksaan: rows.reduce((sum, r) => sum + r.kuota_pemeriksaan, 0),
                    usedPemeriksaan: rows.reduce((sum, r) => sum + r.kuota_terpakai_pemeriksaan, 0),
                    totalKonsultasiGizi: rows.reduce((sum, r) => sum + r.kuota_konsultasi_gizi, 0),
                    usedKonsultasiGizi: rows.reduce((sum, r) => sum + r.kuota_terpakai_konsultasi_gizi, 0),
                    totalKonsultasiTumbuhKembang: rows.reduce((sum, r) => sum + r.kuota_konsultasi_tumbuh_kembang, 0),
                    usedKonsultasiTumbuhKembang: rows.reduce(
                        (sum, r) => sum + r.kuota_terpakai_konsultasi_tumbuh_kembang,
                        0,
                    ),
                };
            }),
        [quotaShifts],
    );

    function applyFilter(e) {
        e.preventDefault();
        router.get(
            route('kuota.index'),
            { tanggal, ...(dokter ? { dokter } : {}) },
            { preserveState: true },
        );
    }

    return (
        <PreLayananLayout header="Kuota Shift">
            <Head title="Kuota Shift" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div className="flex flex-wrap items-end gap-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                        <form
                            onSubmit={applyFilter}
                            className="flex items-end gap-3"
                        >
                            <div>
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Tanggal
                                </label>
                                <input
                                    type="date"
                                    value={tanggal}
                                    onChange={(e) =>
                                        setTanggal(e.target.value)
                                    }
                                    className="mt-1 rounded-lg border-gray-300 text-sm shadow-sm transition-colors focus:border-[#2a78d6] focus:ring-[#2a78d6] dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:focus:border-[#3987e5] dark:focus:ring-[#3987e5]"
                                />
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Dokter
                                </label>
                                <select
                                    value={dokter}
                                    onChange={(e) => setDokter(e.target.value)}
                                    className="mt-1 rounded-lg border-gray-300 text-sm shadow-sm transition-colors focus:border-[#2a78d6] focus:ring-[#2a78d6] dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:focus:border-[#3987e5] dark:focus:ring-[#3987e5]"
                                >
                                    <option value="">Semua Dokter</option>
                                    {doctors.map((d) => (
                                        <option key={d.kode_dokter} value={d.kode_dokter}>
                                            {d.nama_dokter}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <PrimaryButton type="submit">
                                Tampilkan
                            </PrimaryButton>
                        </form>
                    </div>

                    <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                        <h3 className="mb-4 text-sm font-semibold text-gray-900 dark:text-gray-100">
                            Pemakaian Kuota per Shift
                        </h3>
                        <div className="space-y-5">
                            {shiftTotals.map((s) => (
                                <div
                                    key={s.shift}
                                    className="space-y-2.5 rounded-lg bg-gray-50/60 p-3 dark:bg-gray-800/30"
                                >
                                    <ShiftMeter label={s.label} used={s.used} total={s.total} />
                                    <div className="grid gap-2.5 pl-3 sm:grid-cols-3">
                                        <ShiftMeter
                                            label="Periksa Sakit/Imunisasi"
                                            used={s.usedPemeriksaan}
                                            total={s.totalPemeriksaan}
                                        />
                                        <ShiftMeter
                                            label="Konsultasi Gizi"
                                            used={s.usedKonsultasiGizi}
                                            total={s.totalKonsultasiGizi}
                                        />
                                        <ShiftMeter
                                            label="Konsultasi Tumbuh Kembang"
                                            used={s.usedKonsultasiTumbuhKembang}
                                            total={s.totalKonsultasiTumbuhKembang}
                                        />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                        <div className="mb-4 flex items-center justify-between">
                            <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                Antrean Hari Ini
                            </h3>
                            <span className="text-xs text-gray-500 dark:text-gray-400">
                                Nomor antrian diisi otomatis begitu pasien
                                dikonfirmasi "Datang" di halaman Antrean.
                            </span>
                        </div>
                        <div className="overflow-x-auto rounded-lg ring-1 ring-gray-200/70 dark:ring-gray-800">
                            <table className="min-w-full divide-y divide-gray-100 dark:divide-gray-800">
                                <thead className="border-b border-gray-200 bg-gray-50/80 dark:border-gray-800 dark:bg-gray-800/40">
                                    <tr>
                                        {[
                                            'No. Antrian',
                                            'Pasien',
                                            'Poliklinik',
                                            'Dokter',
                                            'Shift',
                                            'Kategori Layanan',
                                        ].map((h) => (
                                            <th
                                                key={h}
                                                className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"
                                            >
                                                {h}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                                    {antrean.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={6}
                                                className="px-4 py-6 text-center text-sm text-gray-500"
                                            >
                                                Belum ada pasien yang datang.
                                            </td>
                                        </tr>
                                    )}
                                    {antrean.map((a) => (
                                        <tr
                                            key={a.id}
                                            className="transition-colors hover:bg-gray-50/80 dark:hover:bg-gray-800/40"
                                        >
                                            <td className="px-4 py-3 text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                                                {a.no_antrean}
                                            </td>
                                            <td className="px-4 py-3 text-sm">
                                                <div className="font-medium text-gray-900 dark:text-gray-100">
                                                    {a.nama_pasien ?? '-'}
                                                </div>
                                                <div className="text-xs text-gray-500 dark:text-gray-400">
                                                    {a.no_rm}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                                                {a.poliklinik ?? '-'}
                                            </td>
                                            <td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                                                {a.dokter ?? '-'}
                                            </td>
                                            <td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                                                {shiftLabel[a.shift] ?? a.shift}
                                            </td>
                                            <td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
                                                {a.jenis_layanan}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </PreLayananLayout>
    );
}
