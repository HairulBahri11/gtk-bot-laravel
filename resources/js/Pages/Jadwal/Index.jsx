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
const shiftPillStyle = {
    pagi: 'bg-sky-50 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
    sore: 'bg-orange-50 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
    malam: 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',
};
const hariOptions = [
    'SENIN',
    'SELASA',
    'RABU',
    'KAMIS',
    'JUMAT',
    'SABTU',
    'MINGGU',
];
const hariByIsoIndex = ['SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT', 'SABTU', 'MINGGU'];

const statusStyle = {
    open: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    delayed: 'bg-amber-50 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    cancelled: 'bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-300',
};
const statusLabel = { open: 'Buka', delayed: 'Delay', cancelled: 'Dibatalkan' };

const inputClass =
    'mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-[#2a78d6] focus:ring-[#2a78d6] dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:focus:border-[#3987e5] dark:focus:ring-[#3987e5]';

function formatTanggalIndo(tanggal) {
    try {
        return new Date(`${tanggal}T00:00:00`).toLocaleDateString('id-ID', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        });
    } catch {
        return tanggal;
    }
}

function toIsoDate(date) {
    return date.toISOString().slice(0, 10);
}

function ChevronDownIcon({ open }) {
    return (
        <svg
            viewBox="0 0 20 20"
            fill="currentColor"
            className={`h-3.5 w-3.5 shrink-0 transition-transform duration-150 ${open ? 'rotate-180' : ''}`}
        >
            <path
                fillRule="evenodd"
                d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z"
                clipRule="evenodd"
            />
        </svg>
    );
}

function StatusBadge({ status, delayMinutes }) {
    const suffix = status === 'delayed' && delayMinutes ? ` +${delayMinutes} mnt` : '';

    return (
        <span
            className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ${statusStyle[status] ?? statusStyle.open}`}
        >
            {statusLabel[status] ?? status}
            {suffix}
        </span>
    );
}

// Stat kuota dengan bar visual - hijau kalau masih longgar, kuning kalau
// menipis (<=25% tersisa), merah kalau habis, supaya bisa discan cepat tanpa
// baca angka satu-satu.
function QuotaStat({ label, title, tersisa, total }) {
    const pct = total > 0 ? Math.min(100, Math.round(((total - tersisa) / total) * 100)) : 0;
    const isFull = tersisa <= 0;
    const isLow = ! isFull && total > 0 && tersisa / total <= 0.25;
    const barColor = isFull ? 'bg-red-500' : isLow ? 'bg-amber-500' : 'bg-emerald-500';
    const textColor = isFull ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-gray-100';

    return (
        <div className="rounded-lg bg-gray-50 p-2.5 dark:bg-gray-800/60" title={title}>
            <p className="truncate text-[11px] font-medium text-gray-500 dark:text-gray-400">{label}</p>
            <p className={`mt-0.5 text-base font-semibold leading-none ${textColor}`}>
                {tersisa}
                <span className="text-xs font-normal text-gray-400 dark:text-gray-500"> / {total}</span>
            </p>
            <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                <div className={`h-full rounded-full ${barColor}`} style={{ width: `${pct}%` }} />
            </div>
        </div>
    );
}

// Editor kuota inline untuk SATU tanggal spesifik (quota_shifts), terpisah
// dari kuota template mingguan (doctor_schedules) yang diedit lewat
// useScheduleEditor() di bawah. Nilai awal diambil ulang setiap kali dibuka
// (bukan disimpan di useForm sejak mount) supaya tidak basi kalau kartu ini
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
        <form onSubmit={submit} className="space-y-2.5 rounded-lg bg-gray-50 p-3 dark:bg-gray-800/60">
            <div className="grid grid-cols-3 gap-2">
                <div>
                    <InputLabel value="Total" className="text-xs" />
                    <TextInput
                        type="number"
                        min="0"
                        value={data.kuota_total}
                        onChange={(e) => setData({ ...data, kuota_total: e.target.value })}
                        className="mt-1 w-full text-sm"
                    />
                </div>
                <div>
                    <InputLabel value="Gizi" className="text-xs" />
                    <TextInput
                        type="number"
                        min="0"
                        value={data.kuota_konsultasi_gizi}
                        onChange={(e) => setData({ ...data, kuota_konsultasi_gizi: e.target.value })}
                        className="mt-1 w-full text-sm"
                    />
                </div>
                <div>
                    <InputLabel value="Tumbuh Kembang" className="text-xs" />
                    <TextInput
                        type="number"
                        min="0"
                        value={data.kuota_konsultasi_tumbuh_kembang}
                        onChange={(e) => setData({ ...data, kuota_konsultasi_tumbuh_kembang: e.target.value })}
                        className="mt-1 w-full text-sm"
                    />
                </div>
            </div>
            {errorMessage && <p className="text-xs text-red-600 dark:text-red-400">{errorMessage}</p>}
            <div className="flex gap-2">
                <PrimaryButton type="submit" disabled={processing} className="flex-1 justify-center">
                    Simpan
                </PrimaryButton>
                <SecondaryButton type="button" disabled={processing} onClick={onDone} className="flex-1 justify-center">
                    Batal
                </SecondaryButton>
            </div>
        </form>
    );
}

// Panel "Kelola Shift" (batalkan/delay/buka kembali) - disembunyikan di
// balik toggle supaya kartu tidak dipenuhi 2 input + 3 tombol yang jarang
// dipakai tiap kali admin cuma mau lihat/ubah kuota.
function ManageShiftPanel({ combo, tanggal, status, onBusyChange }) {
    const [reason, setReason] = useState('');
    const [delayMinutes, setDelayMinutes] = useState(30);
    const [busy, setBusy] = useState(false);

    function act(url) {
        setBusy(true);
        onBusyChange?.(true);
        router.post(
            route(url),
            {
                kode_dokter: combo.kode_dokter,
                tanggal,
                shift: combo.shift,
                reason: reason || undefined,
                delay_minutes: delayMinutes,
            },
            {
                onFinish: () => {
                    setBusy(false);
                    onBusyChange?.(false);
                },
            },
        );
    }

    return (
        <div className="mt-3 space-y-3 border-t border-gray-100 pt-3 dark:border-gray-800">
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
                    className="px-3 py-1.5 text-xs"
                    disabled={busy || status === 'cancelled'}
                    onClick={() => act('jadwal.cancel-shift')}
                >
                    Batalkan Shift
                </DangerButton>
                <SecondaryButton
                    className="px-3 py-1.5 text-xs"
                    disabled={busy || status === 'cancelled'}
                    onClick={() => act('jadwal.delay-shift')}
                >
                    Lapor Delay
                </SecondaryButton>
                {status !== 'open' && (
                    <SecondaryButton className="px-3 py-1.5 text-xs" disabled={busy} onClick={() => act('jadwal.reopen-shift')}>
                        Buka Kembali
                    </SecondaryButton>
                )}
            </div>
        </div>
    );
}

function ShiftStatusCard({ combo, statusRow, tanggal }) {
    const [editingKuota, setEditingKuota] = useState(false);
    const [managing, setManaging] = useState(false);

    const status = statusRow?.status ?? 'open';

    return (
        <div className="space-y-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">
                        {combo.nama_dokter}
                    </p>
                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                        <span className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${shiftPillStyle[combo.shift] ?? ''}`}>
                            {shiftLabel[combo.shift] ?? combo.shift}
                        </span>
                        <span className="text-xs text-gray-500 dark:text-gray-400">
                            {combo.jam_mulai}-{combo.jam_selesai}
                        </span>
                    </div>
                </div>
                <StatusBadge status={status} delayMinutes={statusRow?.delay_minutes} />
            </div>

            <div>
                <div className="mb-1.5 flex items-center justify-between">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                        Kuota
                    </p>
                    {! editingKuota && (
                        <button
                            type="button"
                            onClick={() => setEditingKuota(true)}
                            className="text-xs font-medium text-[#2a78d6] hover:underline dark:text-[#3987e5]"
                        >
                            {statusRow ? 'Ubah' : 'Buat'}
                        </button>
                    )}
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
                            label="Periksa"
                            title="Periksa Sakit/Imunisasi"
                            tersisa={statusRow.kuota_tersisa_pemeriksaan}
                            total={statusRow.kuota_pemeriksaan}
                        />
                        <QuotaStat
                            label="Gizi"
                            title="Konsultasi Gizi"
                            tersisa={statusRow.kuota_tersisa_konsultasi_gizi}
                            total={statusRow.kuota_konsultasi_gizi}
                        />
                        <QuotaStat
                            label="TK"
                            title="Konsultasi Tumbuh Kembang"
                            tersisa={statusRow.kuota_tersisa_konsultasi_tumbuh_kembang}
                            total={statusRow.kuota_konsultasi_tumbuh_kembang}
                        />
                    </div>
                ) : (
                    <p className="rounded-lg border border-dashed border-amber-300 bg-amber-50/60 p-2.5 text-xs text-amber-700 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-400">
                        Belum dibuat untuk tanggal ini - klik "Buat" untuk membuatnya dari jadwal mingguan.
                    </p>
                )}
            </div>

            {statusRow?.reason && (
                <p className="text-xs text-gray-500 dark:text-gray-400">Alasan: {statusRow.reason}</p>
            )}

            {! editingKuota && (
                <div className="border-t border-gray-100 pt-2.5 dark:border-gray-800">
                    <button
                        type="button"
                        onClick={() => setManaging((v) => ! v)}
                        className="flex items-center gap-1 text-xs font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                    >
                        Kelola Shift (batalkan/delay)
                        <ChevronDownIcon open={managing} />
                    </button>

                    {managing && <ManageShiftPanel combo={combo} tanggal={tanggal} status={status} />}
                </div>
            )}
        </div>
    );
}

// Logika edit/hapus baris jadwal mingguan (dipakai bareng oleh ScheduleRow -
// tampilan tabel di desktop - dan ScheduleCard - tampilan kartu di mobile)
// supaya tidak ada 2 implementasi form yang bisa diam-diam beda perilaku.
function useScheduleEditor(schedule) {
    const { data, setData, put, processing, reset, errors, clearErrors } = useForm({
        jam_mulai: schedule.jam_mulai,
        jam_selesai: schedule.jam_selesai,
        kuota_total: schedule.kuota_total,
        kuota_konsultasi_gizi: schedule.kuota_konsultasi_gizi,
        kuota_konsultasi_tumbuh_kembang: schedule.kuota_konsultasi_tumbuh_kembang,
    });
    const [editing, setEditing] = useState(false);

    function submit(e) {
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

    function destroy() {
        if (! confirm('Hapus baris jadwal ini?')) return;
        router.delete(route('jadwal.destroy', schedule.id), { preserveScroll: true });
    }

    return { editing, setEditing, data, setData, submit, cancelEdit, destroy, processing, errors };
}

// Baris tabel jadwal (tampilan >= md) - jam/hari/dokter/poli tidak bisa
// diubah lewat sini (harus hapus+tambah), hanya kuota_total & kedua alokasi
// konsultasi (gizi/tumbuh kembang) lewat endpoint PUT jadwal.update.
function ScheduleRow({ schedule }) {
    const { editing, setEditing, data, setData, submit, cancelEdit, destroy, processing, errors } =
        useScheduleEditor(schedule);

    return (
        <tr className="hover:bg-gray-50/80 dark:hover:bg-gray-800/40">
            <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{schedule.nama_dokter}</td>
            <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{schedule.nama_poliklinik}</td>
            <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{schedule.hari}</td>
            <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                {schedule.jam_mulai}-{schedule.jam_selesai}
            </td>
            <td className="px-4 py-3 text-sm">
                <span className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${shiftPillStyle[schedule.shift] ?? ''}`}>
                    {shiftLabel[schedule.shift] ?? schedule.shift}
                </span>
            </td>
            <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                {editing ? (
                    <form onSubmit={submit} className="flex flex-wrap items-center gap-2">
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
                        <SecondaryButton onClick={destroy}>Hapus</SecondaryButton>
                    </div>
                )}
            </td>
        </tr>
    );
}

// Kartu jadwal mingguan (tampilan < md) - konten sama dengan ScheduleRow,
// cuma disusun vertikal supaya enak dibaca di layar sempit tanpa perlu
// scroll horizontal.
function ScheduleCard({ schedule }) {
    const { editing, setEditing, data, setData, submit, cancelEdit, destroy, processing, errors } =
        useScheduleEditor(schedule);

    return (
        <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">
                        {schedule.nama_dokter}
                    </p>
                    <p className="truncate text-xs text-gray-500 dark:text-gray-400">{schedule.nama_poliklinik}</p>
                </div>
                <span className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium ${shiftPillStyle[schedule.shift] ?? ''}`}>
                    {shiftLabel[schedule.shift] ?? schedule.shift}
                </span>
            </div>

            <div className="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                <span>{schedule.hari}</span>
                <span>&middot;</span>
                <span>
                    {schedule.jam_mulai}-{schedule.jam_selesai}
                </span>
            </div>

            {editing ? (
                <form onSubmit={submit} className="mt-3 space-y-3 border-t border-gray-100 pt-3 dark:border-gray-800">
                    <div className="grid grid-cols-3 gap-2">
                        <div>
                            <InputLabel value="Total" className="text-xs" />
                            <TextInput
                                type="number"
                                min="0"
                                value={data.kuota_total}
                                onChange={(e) => setData('kuota_total', e.target.value)}
                                className="mt-1 w-full text-sm"
                            />
                        </div>
                        <div>
                            <InputLabel value="Gizi" className="text-xs" />
                            <TextInput
                                type="number"
                                min="0"
                                value={data.kuota_konsultasi_gizi}
                                onChange={(e) => setData('kuota_konsultasi_gizi', e.target.value)}
                                className="mt-1 w-full text-sm"
                            />
                        </div>
                        <div>
                            <InputLabel value="TK" className="text-xs" />
                            <TextInput
                                type="number"
                                min="0"
                                value={data.kuota_konsultasi_tumbuh_kembang}
                                onChange={(e) => setData('kuota_konsultasi_tumbuh_kembang', e.target.value)}
                                className="mt-1 w-full text-sm"
                            />
                        </div>
                    </div>
                    {(errors.kuota_total || errors.kuota_konsultasi_gizi || errors.kuota_konsultasi_tumbuh_kembang) && (
                        <p className="text-xs text-red-600">
                            Total kuota konsultasi gizi + tumbuh kembang tidak boleh melebihi kuota total.
                        </p>
                    )}
                    <div className="flex gap-2">
                        <PrimaryButton type="submit" disabled={processing} className="flex-1 justify-center">
                            Simpan
                        </PrimaryButton>
                        <SecondaryButton
                            type="button"
                            onClick={cancelEdit}
                            disabled={processing}
                            className="flex-1 justify-center"
                        >
                            Batal
                        </SecondaryButton>
                    </div>
                </form>
            ) : (
                <div className="mt-3 space-y-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                    <p className="text-sm text-gray-900 dark:text-gray-100">
                        Kuota: <span className="font-semibold">{schedule.kuota_total}</span>{' '}
                        <span className="text-xs font-normal text-gray-500 dark:text-gray-400">
                            (Periksa {schedule.kuota_pemeriksaan} / Gizi {schedule.kuota_konsultasi_gizi} / TK{' '}
                            {schedule.kuota_konsultasi_tumbuh_kembang})
                        </span>
                    </p>
                    <div className="flex gap-2">
                        <SecondaryButton className="flex-1 justify-center" onClick={() => setEditing(true)}>
                            Ubah Kuota
                        </SecondaryButton>
                        <SecondaryButton className="flex-1 justify-center" onClick={destroy}>
                            Hapus
                        </SecondaryButton>
                    </div>
                </div>
            )}
        </div>
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

    function goToOffset(offsetDays) {
        const iso = toIsoDate(new Date(Date.now() + offsetDays * 86400000));
        setTanggal(iso);
        router.get(route('jadwal.index'), { tanggal: iso }, { preserveState: true });
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

            <div className="py-8 sm:py-12">
                <div className="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                        <form onSubmit={applyFilter} className="flex flex-wrap items-end gap-3">
                            <div>
                                <InputLabel value="Tanggal" />
                                <input
                                    type="date"
                                    value={tanggal}
                                    onChange={(e) => setTanggal(e.target.value)}
                                    className={inputClass}
                                />
                            </div>
                            <PrimaryButton type="submit">Tampilkan</PrimaryButton>
                            <div className="flex gap-2">
                                <SecondaryButton type="button" className="px-2.5 py-1.5 text-xs" onClick={() => goToOffset(0)}>
                                    Hari Ini
                                </SecondaryButton>
                                <SecondaryButton type="button" className="px-2.5 py-1.5 text-xs" onClick={() => goToOffset(1)}>
                                    Besok
                                </SecondaryButton>
                            </div>
                        </form>
                        <p className="mt-3 text-sm text-gray-500 dark:text-gray-400">
                            Menampilkan jadwal untuk{' '}
                            <span className="font-medium text-gray-900 dark:text-gray-100">
                                {formatTanggalIndo(tanggal)}
                            </span>
                        </p>
                    </div>

                    <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800 sm:p-6">
                        <h3 className="mb-4 text-sm font-semibold text-gray-900 dark:text-gray-100">
                            Status Shift ({hariForTanggal})
                        </h3>

                        {shiftCombos.length === 0 ? (
                            <p className="rounded-lg border border-dashed border-gray-300 p-4 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                Tidak ada jadwal manual pada hari ini.
                            </p>
                        ) : (
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
                        )}
                    </div>

                    <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800 sm:p-6">
                        <h3 className="mb-4 text-sm font-semibold text-gray-900 dark:text-gray-100">
                            Tambah Jadwal Mingguan
                        </h3>

                        <form onSubmit={submitSchedule} className="space-y-5">
                            <div>
                                <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                                    Hari & Waktu
                                </p>
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                    <div>
                                        <InputLabel value="Hari" />
                                        <select
                                            value={data.hari}
                                            onChange={(e) => setData('hari', e.target.value)}
                                            className={inputClass}
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
                                </div>
                            </div>

                            <div>
                                <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                                    Kuota
                                </p>
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
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
                                        <InputLabel value="Konsultasi Gizi" />
                                        <TextInput
                                            type="number"
                                            min="0"
                                            value={data.kuota_konsultasi_gizi}
                                            onChange={(e) => setData('kuota_konsultasi_gizi', e.target.value)}
                                            className="mt-1 w-full"
                                        />
                                    </div>
                                    <div>
                                        <InputLabel value="Konsultasi Tumbuh Kembang" />
                                        <TextInput
                                            type="number"
                                            min="0"
                                            value={data.kuota_konsultasi_tumbuh_kembang}
                                            onChange={(e) => setData('kuota_konsultasi_tumbuh_kembang', e.target.value)}
                                            className="mt-1 w-full"
                                        />
                                    </div>
                                </div>
                                <p className="mt-2 text-xs leading-snug text-gray-500 dark:text-gray-400">
                                    Sisanya (
                                    {Math.max(
                                        0,
                                        (data.kuota_total || 0) -
                                            (data.kuota_konsultasi_gizi || 0) -
                                            (data.kuota_konsultasi_tumbuh_kembang || 0),
                                    )}
                                    ) otomatis untuk Periksa Sakit/Imunisasi.
                                </p>
                            </div>

                            <div>
                                <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                                    Dokter & Poliklinik
                                </p>
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div>
                                        <InputLabel value="Kode Dokter" />
                                        <select
                                            value={data.kode_dokter}
                                            onChange={(e) => setData('kode_dokter', e.target.value)}
                                            className={inputClass}
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
                                            className={inputClass}
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
                            </div>

                            <PrimaryButton
                                type="submit"
                                disabled={processing || doctors.length === 0 || poliklinik.length === 0}
                            >
                                Tambah Jadwal
                            </PrimaryButton>
                        </form>
                        {doctors.length === 0 && (
                            <p className="mt-3 text-sm text-amber-600 dark:text-amber-400">
                                Belum ada data dokter aktif di master dokter.
                            </p>
                        )}
                        {poliklinik.length === 0 && (
                            <p className="mt-3 text-sm text-amber-600 dark:text-amber-400">
                                Belum ada data poliklinik aktif.
                            </p>
                        )}
                        {Object.keys(errors).length > 0 && (
                            <p className="mt-3 text-sm text-red-600 dark:text-red-400">
                                Periksa kembali isian - ada data yang tidak valid.
                            </p>
                        )}
                    </div>

                    <div>
                        <h3 className="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">
                            Daftar Jadwal Mingguan
                        </h3>

                        {schedules.length === 0 ? (
                            <p className="rounded-xl border border-dashed border-gray-300 bg-white p-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
                                Belum ada jadwal manual. Tambahkan lewat form di atas.
                            </p>
                        ) : (
                            <>
                                {/* Mobile (< md): kartu bertumpuk, hindari tabel lebar yang harus di-scroll horizontal. */}
                                <div className="grid gap-3 md:hidden">
                                    {schedules.map((s) => (
                                        <ScheduleCard key={s.id} schedule={s} />
                                    ))}
                                </div>

                                {/* Desktop (>= md): tabel biasa, dibungkus overflow-x-auto (bukan overflow-hidden) supaya tetap bisa discroll, bukan terpotong, kalau suatu saat lebih sempit dari isinya. */}
                                <div className="hidden overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800 md:block">
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
                                            {schedules.map((s) => (
                                                <ScheduleRow key={s.id} schedule={s} />
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
