import PreLayananLayout from '@/Layouts/PreLayananLayout';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import { Head, Link, router } from '@inertiajs/react';

const shiftLabel = { pagi: 'Pagi', sore: 'Sore', malam: 'Malam' };

const statusStyle = {
    waitlist: 'bg-yellow-50 text-yellow-700 ring-1 ring-yellow-600/20 dark:bg-yellow-900/40 dark:text-yellow-300 dark:ring-yellow-500/20',
    booked: 'bg-blue-50 text-blue-700 ring-1 ring-blue-600/20 dark:bg-blue-900/40 dark:text-blue-300 dark:ring-blue-500/20',
    confirmed: 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-600/20 dark:bg-indigo-900/40 dark:text-indigo-300 dark:ring-indigo-500/20',
    arrived: 'bg-green-50 text-green-700 ring-1 ring-green-600/20 dark:bg-green-900/40 dark:text-green-300 dark:ring-green-500/20',
    no_show: 'bg-red-50 text-red-700 ring-1 ring-red-600/20 dark:bg-red-900/40 dark:text-red-300 dark:ring-red-500/20',
    cancelled: 'bg-gray-100 text-gray-600 ring-1 ring-gray-400/20 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-600/30',
    rescheduled: 'bg-purple-50 text-purple-700 ring-1 ring-purple-600/20 dark:bg-purple-900/40 dark:text-purple-300 dark:ring-purple-500/20',
};

const statusOptions = [
    '', 'waitlist', 'booked', 'confirmed', 'arrived', 'no_show', 'cancelled', 'rescheduled',
];

export default function AntreanIndex({ bookings, filters }) {
    function filterByStatus(status) {
        router.get(
            route('antrean.index'),
            status ? { status } : {},
            { preserveState: true },
        );
    }

    function confirmArrival(id) {
        router.post(route('antrean.confirm-arrival', id));
    }

    function markNoShow(id) {
        if (confirm('Tandai booking ini sebagai No-Show?')) {
            router.post(route('antrean.no-show', id));
        }
    }

    function cancelBooking(id) {
        if (confirm('Batalkan booking ini?')) {
            router.post(route('antrean.cancel', id));
        }
    }

    return (
        <PreLayananLayout header="Antrean">
            <Head title="Antrean" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div className="flex flex-wrap items-center gap-2 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                        <span className="text-sm font-medium text-gray-700 dark:text-gray-300">
                            Filter status:
                        </span>
                        {statusOptions.map((status) => (
                            <button
                                key={status || 'all'}
                                onClick={() => filterByStatus(status)}
                                className={`rounded-full px-3 py-1 text-xs font-medium transition-colors ${
                                    (filters.status ?? '') === status
                                        ? 'bg-[#2a78d6] text-white dark:bg-[#3987e5]'
                                        : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700'
                                }`}
                            >
                                {status || 'Semua'}
                            </button>
                        ))}
                    </div>

                    <div className="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                        <table className="min-w-full divide-y divide-gray-100 dark:divide-gray-800">
                            <thead className="border-b border-gray-200 bg-gray-50/80 dark:border-gray-800 dark:bg-gray-800/40">
                                <tr>
                                    {[
                                        'Pasien',
                                        'No. RM',
                                        'Poliklinik / Dokter',
                                        'Tanggal',
                                        'Shift',
                                        'Status',
                                        'Aksi',
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
                                {bookings.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="px-4 py-6 text-center text-sm text-gray-500"
                                        >
                                            Tidak ada data antrean.
                                        </td>
                                    </tr>
                                )}
                                {bookings.data.map((b) => (
                                    <tr
                                        key={b.id}
                                        className="transition-colors hover:bg-gray-50/80 dark:hover:bg-gray-800/40"
                                    >
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                            {b.nama_pasien ?? '-'}
                                            {b.waitlist_position && (
                                                <span className="ms-1 text-xs text-gray-500">
                                                    (#{b.waitlist_position})
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                            {b.no_rm}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                            {b.poliklinik}
                                            <div className="text-xs text-gray-500">
                                                {b.dokter}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                            {b.tanggal_periksa}
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                            {shiftLabel[b.shift] ?? b.shift}
                                        </td>
                                        <td className="px-4 py-3 text-sm">
                                            <span
                                                className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ${statusStyle[b.status] ?? ''}`}
                                            >
                                                <span className="h-1.5 w-1.5 rounded-full bg-current" />
                                                {b.status}
                                            </span>
                                        </td>
                                        <td className="space-x-2 whitespace-nowrap px-4 py-3 text-sm">
                                            {['booked', 'confirmed'].includes(
                                                b.status,
                                            ) && (
                                                <>
                                                    <SecondaryButton
                                                        onClick={() =>
                                                            confirmArrival(
                                                                b.id,
                                                            )
                                                        }
                                                    >
                                                        Datang
                                                    </SecondaryButton>
                                                    <SecondaryButton
                                                        onClick={() =>
                                                            markNoShow(b.id)
                                                        }
                                                    >
                                                        No-Show
                                                    </SecondaryButton>
                                                </>
                                            )}
                                            {[
                                                'booked',
                                                'confirmed',
                                                'waitlist',
                                            ].includes(b.status) && (
                                                <DangerButton
                                                    onClick={() =>
                                                        cancelBooking(b.id)
                                                    }
                                                >
                                                    Batal
                                                </DangerButton>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {bookings.links && (
                        <div className="flex flex-wrap gap-1">
                            {bookings.links.map((link, i) => (
                                <Link
                                    key={i}
                                    href={link.url ?? '#'}
                                    className={`rounded-lg px-3 py-1.5 text-sm transition-colors ${
                                        link.active
                                            ? 'bg-[#2a78d6] text-white dark:bg-[#3987e5]'
                                            : 'bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-800 dark:hover:bg-gray-800'
                                    } ${!link.url ? 'pointer-events-none opacity-50' : ''}`}
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </PreLayananLayout>
    );
}
