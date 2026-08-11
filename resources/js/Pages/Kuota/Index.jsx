import PreLayananLayout from '@/Layouts/PreLayananLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import ShiftMeter from '@/Components/ShiftMeter';
import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const shiftLabel = { pagi: 'Pagi', sore: 'Sore', malam: 'Malam' };
const shiftOrder = ['pagi', 'sore', 'malam'];

const statusStyle = {
    open: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    delayed: 'bg-amber-50 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    cancelled: 'bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-300',
};

const statusLabel = { open: 'Buka', delayed: 'Delay', cancelled: 'Dibatalkan' };

function StatusBadge({ status, delayMinutes }) {
    const label = statusLabel[status] ?? status;
    const suffix = status === 'delayed' && delayMinutes ? ` +${delayMinutes} mnt` : '';

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ${statusStyle[status] ?? ''}`}
        >
            {label}
            {suffix}
        </span>
    );
}

export default function KuotaIndex({ quotaShifts, filters }) {
    const [tanggal, setTanggal] = useState(filters.tanggal);
    const [syncing, setSyncing] = useState(false);

    const shiftTotals = useMemo(
        () =>
            shiftOrder.map((shift) => {
                const rows = quotaShifts.filter((q) => q.shift === shift);

                return {
                    shift,
                    label: shiftLabel[shift],
                    total: rows.reduce((sum, r) => sum + r.kuota_total, 0),
                    used: rows.reduce((sum, r) => sum + r.kuota_terpakai, 0),
                };
            }),
        [quotaShifts],
    );

    function applyFilter(e) {
        e.preventDefault();
        router.get(
            route('kuota.index'),
            { tanggal },
            { preserveState: true },
        );
    }

    function handleSync() {
        setSyncing(true);
        router.post(
            route('kuota.sync'),
            {},
            { onFinish: () => setSyncing(false) },
        );
    }

    return (
        <PreLayananLayout header="Kuota Shift">
            <Head title="Kuota Shift" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div className="flex flex-wrap items-end justify-between gap-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
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
                            <PrimaryButton type="submit">
                                Tampilkan
                            </PrimaryButton>
                        </form>

                        <PrimaryButton onClick={handleSync} disabled={syncing}>
                            {syncing
                                ? 'Menyinkronkan...'
                                : 'Sinkronkan dari GTK'}
                        </PrimaryButton>
                    </div>

                    <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                        <h3 className="mb-4 text-sm font-semibold text-gray-900 dark:text-gray-100">
                            Pemakaian Kuota per Shift
                        </h3>
                        <div className="space-y-4">
                            {shiftTotals.map((s) => (
                                <ShiftMeter
                                    key={s.shift}
                                    label={s.label}
                                    used={s.used}
                                    total={s.total}
                                />
                            ))}
                        </div>
                    </div>

                    <div className="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                        <table className="min-w-full divide-y divide-gray-100 dark:divide-gray-800">
                            <thead className="border-b border-gray-200 bg-gray-50/80 dark:border-gray-800 dark:bg-gray-800/40">
                                <tr>
                                    {[
                                        'Poliklinik',
                                        'Dokter',
                                        'Shift',
                                        'Status',
                                        'Kuota Total',
                                        'Terpakai',
                                        'Tersisa',
                                        'Sinkron Terakhir',
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
                                {quotaShifts.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={8}
                                            className="px-4 py-6 text-center text-sm text-gray-500"
                                        >
                                            Tidak ada data kuota untuk
                                            tanggal ini.
                                        </td>
                                    </tr>
                                )}
                                {quotaShifts.map((q) => (
                                    <tr
                                        key={q.id}
                                        className="transition-colors hover:bg-gray-50/80 dark:hover:bg-gray-800/40"
                                    >
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                            {q.nama_poliklinik}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                            {q.nama_dokter}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                            {shiftLabel[q.shift] ?? q.shift}
                                        </td>
                                        <td className="px-4 py-3 text-sm">
                                            <StatusBadge
                                                status={q.status}
                                                delayMinutes={q.delay_minutes}
                                            />
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                            {q.kuota_total}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                            {q.kuota_terpakai}
                                        </td>
                                        <td className="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-gray-100">
                                            {q.kuota_tersisa}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                            {q.last_synced_at ?? '-'}
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
