import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
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

function QuotaStat({ label, tersisa, total }) {
    return (
        <div className="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-800/60">
            <p className="text-xs text-gray-500 dark:text-gray-400">{label}</p>
            <p className="text-sm font-semibold text-gray-900 dark:text-gray-100">
                {tersisa} <span className="font-normal text-gray-400 dark:text-gray-500">/ {total}</span>
            </p>
        </div>
    );
}

// Editor kuota inline untuk SATU tanggal spesifik (quota_shifts), terpisah
// dari kuota template mingguan (doctor_schedules) yang diedit lewat
// ScheduleRow di bawah. Nilai awal diambil ulang setiap kali dibuka (bukan
// disimpan di useForm sejak mount) supaya tidak basi kalau kartu ini
// dipakai ulang React untuk kombinasi dokter+shift yang sama di tanggal
// lain (statusRow/combo berubah tanpa remount, karena key hanya
// kode_dokter+shift).
function KuotaTanggalEditor({ combo, statusRow, tanggal, onDone }) {
    const [data, setData] = useState({
        kuota_total: statusRow?.kuota_total ?? combo.kuota_total,
        kuota_konsultasi_gizi: statusRow?.kuota_konsultasi_gizi ?? combo.kuota_konsultasi_gizi,
        kuota_konsultasi_tumbuh_kembang:
            statusRow?.kuota_konsultasi_tumbuh_kembang ?? combo.kuota_konsultasi_tumbuh_kembang,
    });
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState({});

    function submit(e) {
        e.preventDefault();
        setProcessing(true);
        router.post(
            route('jadwal.update-kuota-tanggal'),
            {
                kode_dokter: combo.kode_dokter,
                tanggal,
                shift: combo.shift,
                ...data,
            },
            {
                preserveScroll: true,
                onSuccess: onDone,
                onError: setErrors,
                onFinish: () => setProcessing(false),
            },
        );
    }

    const errorMessage = errors.kuota_total || errors.kuota_konsultasi_gizi || errors.kuota_konsultasi_tumbuh_kembang;

    return (
        <form onSubmit={submit} className="space-y-2 rounded-lg bg-gray-50 p-3 dark:bg-gray-800/60">
            <div className="grid grid-cols-3 gap-2">
                <div>
                    <InputLabel value="Kuota Total" className="text-xs" />
                    <TextInput
                        type="number"
                        min="0"
                        value={data.kuota_total}
                        onChange={(e) => setData({ ...data, kuota_total: e.target.value })}
                        className="mt-1 w-full text-sm"
                    />
                </div>
                <div>
                    <InputLabel value="Konsultasi Gizi" className="text-xs" />
                    <TextInput
                        type="number"
                        min="0"
                        value={data.kuota_konsultasi_gizi}
                        onChange={(e) => setData({ ...data, kuota_konsultasi_gizi: e.target.value })}
                        className="mt-1 w-full text-sm"
                    />
                </div>
                <div>
                    <InputLabel value="Konsultasi Tumbuh Kembang" className="text-xs" />
                    <TextInput
                        type="number"
                        min="0"
                        value={data.kuota_konsultasi_tumbuh_kembang}
                        onChange={(e) => setData({ ...data, kuota_konsultasi_tumbuh_kembang: e.target.value })}
                        className="mt-1 w-full text-sm"
                    />
                </div>
            </div>
            {errorMessage && <p className="text-xs text-red-600">{errorMessage}</p>}
            <div className="flex gap-2">
                <PrimaryButton type="submit" disabled={processing}>
                    Simpan
                </PrimaryButton>
                <SecondaryButton type="button" disabled={processing} onClick={onDone}>
                    Batal
                </SecondaryButton>
            </div>
        </form>
    );
}

function ShiftStatusCard({ combo, statusRow, tanggal, isDokter }) {
    const [reason, setReason] = useState('');
    const [delayMinutes, setDelayMinutes] = useState(30);
    const [busy, setBusy] = useState(false);
    const [editingKuota, setEditingKuota] = useState(false);

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

            {editingKuota ? (
                <KuotaTanggalEditor
                    combo={combo}
                    statusRow={statusRow}
                    tanggal={tanggal}
                    onDone={() => setEditingKuota(false)}
                />
            ) : statusRow ? (
                <div className="grid grid-cols-3 gap-2">
                    <QuotaStat
                        label="Periksa Sakit/Imunisasi tersisa"
                        tersisa={statusRow.kuota_tersisa_pemeriksaan}
                        total={statusRow.kuota_pemeriksaan}
                    />
                    <QuotaStat
                        label="Konsultasi Gizi tersisa"
                        tersisa={statusRow.kuota_tersisa_konsultasi_gizi}
                        total={statusRow.kuota_konsultasi_gizi}
                    />
                    <QuotaStat
                        label="Konsultasi Tumbuh Kembang tersisa"
                        tersisa={statusRow.kuota_tersisa_konsultasi_tumbuh_kembang}
                        total={statusRow.kuota_konsultasi_tumbuh_kembang}
                    />
                </div>
            ) : (
                <p className="text-xs text-amber-600 dark:text-amber-400">
                    Kuota untuk tanggal ini belum tersinkron dari jadwal - klik "Ubah Kuota" di bawah untuk
                    membuat & mengustomisasinya sekarang, atau tunggu sinkronisasi otomatis berikutnya.
                </p>
            )}

            {statusRow?.reason && (
                <p className="text-xs text-gray-500 dark:text-gray-400">
                    Alasan: {statusRow.reason}
                </p>
            )}

            {! editingKuota && (
                <>
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
                        <SecondaryButton disabled={busy} onClick={() => setEditingKuota(true)}>
                            Ubah Kuota
                        </SecondaryButton>
                    </div>
                </>
            )}
        </div>
    );
}

// Baris tabel jadwal, dengan mode "ubah kuota" inline - jam/hari/dokter/poli
// tidak bisa diubah lewat sini (harus hapus+tambah), hanya kuota_total &
// kedua alokasi konsultasi (gizi/tumbuh kembang) lewat endpoint PUT
// jadwal.update yang sudah ada.
function ScheduleRow({ schedule }) {
    const [editing, setEditing] = useState(false);
    const { data, setData, put, processing, reset, errors, clearErrors } = useForm({
        jam_mulai: schedule.jam_mulai,
        jam_selesai: schedule.jam_selesai,
        kuota_total: schedule.kuota_total,
        kuota_konsultasi_gizi: schedule.kuota_konsultasi_gizi,
        kuota_konsultasi_tumbuh_kembang: schedule.kuota_konsultasi_tumbuh_kembang,
    });

    function submitKuota(e) {
        e.preventDefault();
        put(route('jadwal.update', schedule.id), {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    }

    function cancelEdit() {
        reset();
        clearErrors();
        setEditing(false);
    }

    function deleteSchedule() {
        if (! confirm('Hapus baris jadwal ini?')) return;
        router.delete(route('jadwal.destroy', schedule.id), { preserveScroll: true });
    }

    return (
        <tr className="hover:bg-gray-50/80 dark:hover:bg-gray-800/40">
            <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{schedule.nama_dokter}</td>
            <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{schedule.nama_poliklinik}</td>
            <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{schedule.hari}</td>
            <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                {schedule.jam_mulai}-{schedule.jam_selesai}
            </td>
            <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                {shiftLabel[schedule.shift] ?? schedule.shift}
            </td>
            <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                {editing ? (
                    <form onSubmit={submitKuota} className="flex flex-wrap items-center gap-2">
                        <div className="flex items-center gap-1">
                            <input
                                type="number"
                                min="0"
                                value={data.kuota_total}
                                onChange={(e) => setData('kuota_total', e.target.value)}
                                aria-label="Kuota Total"
                                className="w-16 rounded-md border-gray-300 text-sm shadow-sm focus:border-[#2a78d6] focus:ring-[#2a78d6] dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"
                            />
                            <span className="text-xs text-gray-400">/</span>
                            <input
                                type="number"
                                min="0"
                                value={data.kuota_konsultasi_gizi}
                                onChange={(e) => setData('kuota_konsultasi_gizi', e.target.value)}
                                aria-label="Kuota Konsultasi Gizi"
                                title="Kuota Konsultasi Gizi"
                                className="w-16 rounded-md border-gray-300 text-sm shadow-sm focus:border-[#2a78d6] focus:ring-[#2a78d6] dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"
                            />
                            <span className="text-xs text-gray-400">/</span>
                            <input
                                type="number"
                                min="0"
                                value={data.kuota_konsultasi_tumbuh_kembang}
                                onChange={(e) => setData('kuota_konsultasi_tumbuh_kembang', e.target.value)}
                                aria-label="Kuota Konsultasi Tumbuh Kembang"
                                title="Kuota Konsultasi Tumbuh Kembang"
                                className="w-16 rounded-md border-gray-300 text-sm shadow-sm focus:border-[#2a78d6] focus:ring-[#2a78d6] dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"
                            />
                        </div>
                        <PrimaryButton type="submit" disabled={processing}>
                            Simpan
                        </PrimaryButton>
                        <SecondaryButton type="button" onClick={cancelEdit} disabled={processing}>
                            Batal
                        </SecondaryButton>
                        {(errors.kuota_total || errors.kuota_konsultasi_gizi || errors.kuota_konsultasi_tumbuh_kembang) && (
                            <p className="w-full text-xs text-red-600">
                                Total kuota konsultasi gizi + tumbuh kembang tidak boleh melebihi kuota total.
                            </p>
                        )}
                    </form>
                ) : (
                    <>
                        {schedule.kuota_total}{' '}
                        <span className="text-xs text-gray-500 dark:text-gray-400">
                            (Periksa {schedule.kuota_pemeriksaan} / Gizi {schedule.kuota_konsultasi_gizi} / TK{' '}
                            {schedule.kuota_konsultasi_tumbuh_kembang})
                        </span>
                    </>
                )}
            </td>
            <td className="px-4 py-3 text-sm">
                {! editing && (
                    <div className="flex flex-wrap gap-2">
                        <SecondaryButton onClick={() => setEditing(true)}>Ubah Kuota</SecondaryButton>
                        <SecondaryButton onClick={deleteSchedule}>Hapus</SecondaryButton>
                    </div>
                )}
            </td>
        </tr>
    );
}

export default function JadwalIndex({ schedules, statuses, doctors, poliklinik, filters, isDokter }) {
    const [tanggal, setTanggal] = useState(filters.tanggal);

    const { data, setData, post, processing, reset, errors } = useForm({
        kode_dokter: doctors[0]?.kode_dokter ?? schedules[0]?.kode_dokter ?? '',
        kode_poliklinik: poliklinik[0]?.kode_poliklinik ?? '',
        hari: 'SENIN',
        jam_mulai: '08:00',
        jam_selesai: '09:30',
        kuota_total: 15,
        kuota_konsultasi_gizi: 0,
        kuota_konsultasi_tumbuh_kembang: 1,
    });

    function applyFilter(e) {
        e.preventDefault();
        router.get(route('jadwal.index'), { tanggal }, { preserveState: true });
    }

    function submitSchedule(e) {
        e.preventDefault();
        post(route('jadwal.store'), { preserveScroll: true, onSuccess: () => reset('jam_mulai', 'jam_selesai') });
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
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Jadwal Dokter
                </h2>
            }
        >
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

                        <form onSubmit={submitSchedule} className="space-y-4">
                            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-7">
                                <div>
                                    <InputLabel value="Hari" />
                                    <select
                                        value={data.hari}
                                        onChange={(e) => setData('hari', e.target.value)}
                                        className="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-[#2a78d6] focus:ring-[#2a78d6] dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"
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
                                        className="mt-1 w-full"
                                    />
                                </div>
                                <div>
                                    <InputLabel value="Jam Selesai" />
                                    <TextInput
                                        type="time"
                                        value={data.jam_selesai}
                                        onChange={(e) => setData('jam_selesai', e.target.value)}
                                        className="mt-1 w-full"
                                    />
                                </div>
                                <div>
                                    <InputLabel value="Kuota Total" />
                                    <TextInput
                                        type="number"
                                        min="0"
                                        value={data.kuota_total}
                                        onChange={(e) => setData('kuota_total', e.target.value)}
                                        className="mt-1 w-full"
                                    />
                                </div>
                                <div>
                                    <InputLabel value="Kuota Konsultasi Gizi" />
                                    <TextInput
                                        type="number"
                                        min="0"
                                        value={data.kuota_konsultasi_gizi}
                                        onChange={(e) => setData('kuota_konsultasi_gizi', e.target.value)}
                                        className="mt-1 w-full"
                                    />
                                </div>
                                <div>
                                    <InputLabel value="Kuota Konsultasi Tumbuh Kembang" />
                                    <TextInput
                                        type="number"
                                        min="0"
                                        value={data.kuota_konsultasi_tumbuh_kembang}
                                        onChange={(e) => setData('kuota_konsultasi_tumbuh_kembang', e.target.value)}
                                        className="mt-1 w-full"
                                    />
                                    <p className="mt-1 text-xs leading-snug text-gray-500 dark:text-gray-400">
                                        Sisanya (
                                        {Math.max(
                                            0,
                                            (data.kuota_total || 0) -
                                                (data.kuota_konsultasi_gizi || 0) -
                                                (data.kuota_konsultasi_tumbuh_kembang || 0),
                                        )}
                                        ) untuk Periksa Sakit/Imunisasi.
                                    </p>
                                </div>
                                <div>
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
                                <div>
                                    <InputLabel value="Poliklinik" />
                                    <select
                                        value={data.kode_poliklinik}
                                        onChange={(e) => setData('kode_poliklinik', e.target.value)}
                                        className="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-[#2a78d6] focus:ring-[#2a78d6] dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100"
                                    >
                                        <option value="" disabled>
                                            Pilih poliklinik
                                        </option>
                                        {poliklinik.map((p) => (
                                            <option key={p.kode_poliklinik} value={p.kode_poliklinik}>
                                                {p.nama_poliklinik}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            </div>
                            <PrimaryButton
                                type="submit"
                                disabled={processing || doctors.length === 0 || poliklinik.length === 0}
                            >
                                Tambah
                            </PrimaryButton>
                        </form>
                        {doctors.length === 0 && (
                            <p className="mt-2 text-sm text-amber-600">
                                Belum ada data dokter aktif di master dokter.
                            </p>
                        )}
                        {poliklinik.length === 0 && (
                            <p className="mt-2 text-sm text-amber-600">
                                Belum ada data poliklinik aktif.
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
                                    {['Dokter', 'Poliklinik', 'Hari', 'Jam', 'Shift', 'Kuota (Periksa/Gizi/TK)', ''].map(
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
                                    <ScheduleRow key={s.id} schedule={s} />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
