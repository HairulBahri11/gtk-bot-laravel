import PreLayananLayout from '@/Layouts/PreLayananLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const shiftLabel = { pagi: 'Pagi', sore: 'Sore', malam: 'Malam' };
const shiftOrder = ['pagi', 'sore', 'malam'];
const hariOptions = [
    'SENIN',
    'SELASA',
    'RABU',
    'KAMIS',
    'JUMAT',
    'SABTU',
    'MINGGU',
];

const statusStyle = {
    open: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    delayed: 'bg-amber-50 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    cancelled: 'bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-300',
};
const statusLabel = { open: 'Buka', delayed: 'Delay', cancelled: 'Dibatalkan' };

function StatusBadge({ status, delayMinutes }) {
    const suffix = status === 'delayed' && delayMinutes ? ` +${delayMinutes} mnt` : '';

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ${statusStyle[status] ?? statusStyle.open}`}
        >
            {statusLabel[status] ?? status}
            {suffix}
        </span>
    );
}

function ShiftStatusCard({ combo, statusRow, tanggal, isDokter }) {
    const [reason, setReason] = useState('');
    const [delayMinutes, setDelayMinutes] = useState(30);
    const [busy, setBusy] = useState(false);

    const status = statusRow?.status ?? 'open';

    function act(url) {
        setBusy(true);
        router.post(
            route(url),
            {
                kode_dokter: combo.kode_dokter,
                tanggal,
                shift: combo.shift,
                reason: reason || undefined,
                delay_minutes: delayMinutes,
            },
            { onFinish: () => setBusy(false) },
        );
    }

    return (
        <div className="space-y-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
            <div className="flex items-center justify-between">
                <div>
                    <p className="text-sm font-semibold text-gray-900 dark:text-gray-100">
                        {combo.nama_dokter} - {shiftLabel[combo.shift]}
                    </p>
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                        {combo.jam_mulai}-{combo.jam_selesai}
                    </p>
                </div>
                <StatusBadge
                    status={status}
                    delayMinutes={statusRow?.delay_minutes}
                />
            </div>

            {statusRow?.reason && (
                <p className="text-xs text-gray-500 dark:text-gray-400">
                    Alasan: {statusRow.reason}
                </p>
            )}

            <div className="flex flex-wrap items-end gap-2">
                <div className="min-w-[10rem] flex-1">
                    <InputLabel value="Alasan (opsional)" className="text-xs" />
                    <TextInput
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        className="mt-1 w-full text-sm"
                        placeholder="mis. dokter cuti"
                    />
                </div>
                <div className="w-24">
                    <InputLabel value="Delay (mnt)" className="text-xs" />
                    <TextInput
                        type="number"
                        min="1"
                        value={delayMinutes}
                        onChange={(e) => setDelayMinutes(e.target.value)}
                        className="mt-1 w-full text-sm"
                    />
                </div>
            </div>

            <div className="flex flex-wrap gap-2">
                <DangerButton
                    disabled={busy || status === 'cancelled'}
                    onClick={() => act('jadwal.cancel-shift')}
                >
                    Batalkan Shift
                </DangerButton>
                <SecondaryButton
                    disabled={busy || status === 'cancelled'}
                    onClick={() => act('jadwal.delay-shift')}
                >
                    Lapor Delay
                </SecondaryButton>
                {status !== 'open' && (
                    <SecondaryButton disabled={busy} onClick={() => act('jadwal.reopen-shift')}>
                        Buka Kembali
                    </SecondaryButton>
                )}
            </div>
        </div>
    );
}

export default function JadwalIndex({ schedules, statuses, doctors, filters, isDokter }) {
    const [tanggal, setTanggal] = useState(filters.tanggal);

    const { data, setData, post, processing, reset, errors } = useForm({
        kode_dokter: doctors[0]?.kode_dokter ?? schedules[0]?.kode_dokter ?? '',
        hari: 'SENIN',
        jam_mulai: '08:00',
        jam_selesai: '09:30',
        kuota_total: 15,
    });

    function applyFilter(e) {
        e.preventDefault();
        router.get(route('jadwal.index'), { tanggal }, { preserveState: true });
    }

    function submitSchedule(e) {
        e.preventDefault();
        post(route('jadwal.store'), { preserveScroll: true, onSuccess: () => reset('jam_mulai', 'jam_selesai') });
    }

    function deleteSchedule(id) {
        if (! confirm('Hapus baris jadwal ini?')) return;
        router.delete(route('jadwal.destroy', id), { preserveScroll: true });
    }

    const statusByKey = useMemo(
        () => Object.fromEntries(statuses.map((s) => [`${s.kode_dokter}|${s.shift}`, s])),
        [statuses],
    );

    const hariForTanggal = useMemo(() => {
        const hariByIsoIndex = ['SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT', 'SABTU', 'MINGGU'];
        const iso = new Date(`${tanggal}T00:00:00`).getDay();
        return hariByIsoIndex[iso === 0 ? 6 : iso - 1];
    }, [tanggal]);

    const shiftCombos = useMemo(() => {
        const seen = new Map();
        schedules
            .filter((s) => s.hari === hariForTanggal)
            .forEach((s) => {
                const key = `${s.kode_dokter}|${s.shift}`;
                if (! seen.has(key)) seen.set(key, s);
            });
        return Array.from(seen.values()).sort(
            (a, b) => shiftOrder.indexOf(a.shift) - shiftOrder.indexOf(b.shift),
        );
    }, [schedules, hariForTanggal]);

    return (
        <PreLayananLayout header="Jadwal Dokter">
            <Head title="Jadwal Dokter" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div className="flex flex-wrap items-end justify-between gap-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                        <form onSubmit={applyFilter} className="flex items-end gap-3">
                            <div>
                                <InputLabel value="Tanggal" />
                                <input
                                    type="date"
                                    value={tanggal}
                                    onChange={(e) => setTanggal(e.target.value)}
                                    className="mt-1 rounded-lg border-gray-300 text-sm shadow-sm transition-colors focus:border-[#2a78d6] focus:ring-[#2a78d6] dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:focus:border-[#3987e5] dark:focus:ring-[#3987e5]"
                                />
                            </div>
                            <PrimaryButton type="submit">Tampilkan</PrimaryButton>
                        </form>
                    </div>

                    <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                        <h3 className="mb-4 text-sm font-semibold text-gray-900 dark:text-gray-100">
                            Status Shift - {tanggal} ({hariForTanggal})
                        </h3>

                        {shiftCombos.length === 0 && (
                            <p className="text-sm text-gray-500 dark:text-gray-400">
                                Tidak ada jadwal manual pada hari ini.
                            </p>
                        )}

                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {shiftCombos.map((combo) => (
                                <ShiftStatusCard
                                    key={`${combo.kode_dokter}|${combo.shift}`}
                                    combo={combo}
                                    statusRow={statusByKey[`${combo.kode_dokter}|${combo.shift}`]}
                                    tanggal={tanggal}
                                    isDokter={isDokter}
                                />
                            ))}
                        </div>
                    </div>

                    <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                        <h3 className="mb-4 text-sm font-semibold text-gray-900 dark:text-gray-100">
                            Tambah Jadwal Mingguan
                        </h3>

                        <form onSubmit={submitSchedule} className="flex flex-wrap items-end gap-3">
                            <div>
                                <InputLabel value="Hari" />
                                <select
                                    value={data.hari}
                                    onChange={(e) => setData('hari', e.target.value)}
                                    className="mt-1 rounded-lg border-gray-300 text-sm shadow-sm focus:border-[#2a78d6] focus:ring-[#2a78d6] dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"
                                >
                                    {hariOptions.map((h) => (
                                        <option key={h} value={h}>
                                            {h}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <InputLabel value="Jam Mulai" />
                                <TextInput
                                    type="time"
                                    value={data.jam_mulai}
                                    onChange={(e) => setData('jam_mulai', e.target.value)}
                                    className="mt-1"
                                />
                            </div>
                            <div>
                                <InputLabel value="Jam Selesai" />
                                <TextInput
                                    type="time"
                                    value={data.jam_selesai}
                                    onChange={(e) => setData('jam_selesai', e.target.value)}
                                    className="mt-1"
                                />
                            </div>
                            <div className="w-28">
                                <InputLabel value="Kuota" />
                                <TextInput
                                    type="number"
                                    min="0"
                                    value={data.kuota_total}
                                    onChange={(e) => setData('kuota_total', e.target.value)}
                                    className="mt-1 w-full"
                                />
                            </div>
                            <div className="w-56">
                                <InputLabel value="Kode Dokter" />
                                <select
                                    value={data.kode_dokter}
                                    onChange={(e) => setData('kode_dokter', e.target.value)}
                                    className="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-[#2a78d6] focus:ring-[#2a78d6] dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"
                                >
                                    <option value="" disabled>
                                        Pilih dokter
                                    </option>
                                    {doctors.map((d) => (
                                        <option key={d.kode_dokter} value={d.kode_dokter}>
                                            {d.nama_dokter} ({d.kode_dokter})
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <PrimaryButton type="submit" disabled={processing || doctors.length === 0}>
                                Tambah
                            </PrimaryButton>
                        </form>
                        {doctors.length === 0 && (
                            <p className="mt-2 text-sm text-amber-600">
                                Belum ada data dokter aktif di master dokter - sync dulu lewat halaman Kuota.
                            </p>
                        )}
                        {Object.keys(errors).length > 0 && (
                            <p className="mt-2 text-sm text-red-600">
                                Periksa kembali isian - ada data yang tidak valid.
                            </p>
                        )}
                    </div>

                    <div className="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                        <table className="min-w-full divide-y divide-gray-100 dark:divide-gray-800">
                            <thead className="border-b border-gray-200 bg-gray-50/80 dark:border-gray-800 dark:bg-gray-800/40">
                                <tr>
                                    {['Dokter', 'Poliklinik', 'Hari', 'Jam', 'Shift', 'Kuota', ''].map(
                                        (h) => (
                                            <th
                                                key={h}
                                                className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"
                                            >
                                                {h}
                                            </th>
                                        ),
                                    )}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                                {schedules.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="px-4 py-6 text-center text-sm text-gray-500">
                                            Belum ada jadwal manual. Tambahkan lewat form di atas.
                                        </td>
                                    </tr>
                                )}
                                {schedules.map((s) => (
                                    <tr key={s.id} className="hover:bg-gray-50/80 dark:hover:bg-gray-800/40">
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                            {s.nama_dokter}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                            {s.nama_poliklinik}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                            {s.hari}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                            {s.jam_mulai}-{s.jam_selesai}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                            {shiftLabel[s.shift] ?? s.shift}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                            {s.kuota_total}
                                        </td>
                                        <td className="px-4 py-3 text-sm">
                                            <SecondaryButton onClick={() => deleteSchedule(s.id)}>
                                                Hapus
                                            </SecondaryButton>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </PreLayananLayout>
    );
}
