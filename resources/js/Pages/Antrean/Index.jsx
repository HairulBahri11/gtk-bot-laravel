import PreLayananLayout from '@/Layouts/PreLayananLayout';
import SecondaryButton from '@/Components/SecondaryButton';
import DangerButton from '@/Components/DangerButton';
import ChatTranscriptModal from '@/Components/ChatTranscriptModal';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

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

const statusLabel = {
    waitlist: 'Daftar Tunggu',
    booked: 'Belum Datang',
    confirmed: 'Dikonfirmasi H-30m',
    arrived: 'Sudah Datang',
    no_show: 'No-Show',
    cancelled: 'Dibatalkan',
    rescheduled: 'Dijadwalkan Ulang',
};

const statusOptions = [
    '', 'waitlist', 'booked', 'confirmed', 'arrived', 'no_show', 'cancelled', 'rescheduled',
];

// Palet warna chip "Layanan" - dipilih berdasarkan hash nama poliklinik
// supaya konsisten per poliklinik tanpa perlu daftar warna manual per nama
// (poliklinik baru dari sinkronisasi GTK otomatis kebagian warna).
const SERVICE_PALETTE = [
    'bg-blue-50 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    'bg-teal-50 text-teal-700 dark:bg-teal-900/40 dark:text-teal-300',
    'bg-purple-50 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
    'bg-amber-50 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    'bg-pink-50 text-pink-700 dark:bg-pink-900/40 dark:text-pink-300',
    'bg-cyan-50 text-cyan-700 dark:bg-cyan-900/40 dark:text-cyan-300',
];

function serviceColor(name) {
    if (!name) return SERVICE_PALETTE[0];
    let hash = 0;
    for (let i = 0; i < name.length; i++) {
        hash = (hash * 31 + name.charCodeAt(i)) >>> 0;
    }
    return SERVICE_PALETTE[hash % SERVICE_PALETTE.length];
}

export default function AntreanIndex({ bookings, filters, funnel }) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [openChatSessionId, setOpenChatSessionId] = useState(null);

    function applyFilters(overrides = {}) {
        router.get(
            route('antrean.index'),
            {
                ...(filters.status ? { status: filters.status } : {}),
                ...(filters.tanggal ? { tanggal: filters.tanggal } : {}),
                ...(search ? { search } : {}),
                ...overrides,
            },
            { preserveState: true, replace: true },
        );
    }

    function filterByStatus(status) {
        applyFilters({ status: status || undefined });
    }

    function filterByTanggal(tanggal) {
        applyFilters({ tanggal });
    }

    function submitSearch(e) {
        e.preventDefault();
        applyFilters({ search: search || undefined });
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
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                        {funnel.map((f) => (
                            <button
                                key={f.status}
                                type="button"
                                onClick={() => filterByStatus(f.status)}
                                className={`rounded-xl bg-white p-4 text-left shadow-sm ring-1 transition-shadow hover:shadow-md dark:bg-gray-900 ${
                                    (filters.status ?? '') === f.status
                                        ? 'ring-2 ring-[#2a78d6] dark:ring-[#3987e5]'
                                        : 'ring-1 ring-gray-200/70 dark:ring-gray-800'
                                }`}
                            >
                                <span
                                    className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium ${statusStyle[f.status] ?? ''}`}
                                >
                                    <span className="h-1.5 w-1.5 rounded-full bg-current" />
                                    {f.label}
                                </span>
                                <div className="mt-2 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                                    {f.count}
                                </div>
                            </button>
                        ))}
                    </div>

                    <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    Antrean Realtime
                                </h3>
                                <p className="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    Data pendaftaran mengalir dari AI WhatsApp Bot. Keluhan diklasifikasi otomatis.
                                </p>
                            </div>
                            <SecondaryButton
                                onClick={() =>
                                    window.alert(
                                        'Fitur broadcast pengingat H-30 menit akan segera hadir.',
                                    )
                                }
                            >
                                Broadcast H-30m
                            </SecondaryButton>
                        </div>

                        <div className="mt-4 flex flex-wrap items-center gap-2">
                            <form onSubmit={submitSearch} className="flex-1 min-w-[200px]">
                                <input
                                    type="text"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Cari pasien (nama / No. RM / no. HP)..."
                                    className="w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-[#2a78d6] focus:ring-[#2a78d6] dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                                />
                            </form>
                            <select
                                value={filters.status ?? ''}
                                onChange={(e) => filterByStatus(e.target.value)}
                                className="rounded-lg border-gray-300 text-sm shadow-sm focus:border-[#2a78d6] focus:ring-[#2a78d6] dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                            >
                                {statusOptions.map((s) => (
                                    <option key={s || 'all'} value={s}>
                                        {s ? (statusLabel[s] ?? s) : 'Semua Status'}
                                    </option>
                                ))}
                            </select>
                            <input
                                type="date"
                                value={filters.tanggal ?? ''}
                                onChange={(e) => filterByTanggal(e.target.value)}
                                className="rounded-lg border-gray-300 text-sm shadow-sm focus:border-[#2a78d6] focus:ring-[#2a78d6] dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                            />
                        </div>
                    </div>

                    <div className="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                        <table className="min-w-full divide-y divide-gray-100 dark:divide-gray-800">
                            <thead className="border-b border-gray-200 bg-gray-50/80 dark:border-gray-800 dark:bg-gray-800/40">
                                <tr>
                                    {[
                                        '#',
                                        'Pasien',
                                        'Layanan',
                                        'Slot',
                                        'Keluhan (AI-classified)',
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
                                {bookings.data.map((b, i) => (
                                    <tr
                                        key={b.id}
                                        className="transition-colors hover:bg-gray-50/80 dark:hover:bg-gray-800/40"
                                    >
                                        <td className="px-4 py-3 text-sm tabular-nums text-gray-500 dark:text-gray-400">
                                            {String(
                                                (bookings.current_page - 1) * bookings.per_page + i + 1,
                                            ).padStart(2, '0')}
                                        </td>
                                        <td className="px-4 py-3 text-sm">
                                            <div className="font-medium text-gray-900 dark:text-gray-100">
                                                {b.nama_pasien ?? '-'}
                                                {b.waitlist_position && (
                                                    <span className="ms-1 text-xs text-gray-500">
                                                        (#{b.waitlist_position})
                                                    </span>
                                                )}
                                            </div>
                                            <div className="text-xs text-gray-500 dark:text-gray-400">
                                                {[b.usia, b.no_hp].filter(Boolean).join(' · ')}
                                                {!b.usia && !b.no_hp && b.no_rm}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-sm">
                                            {b.poliklinik && (
                                                <span
                                                    className={`inline-block rounded-full px-2.5 py-1 text-xs font-medium ${serviceColor(b.poliklinik)}`}
                                                >
                                                    {b.poliklinik}
                                                </span>
                                            )}
                                            <div className="mt-1 text-xs text-gray-500">
                                                {b.dokter}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                            {shiftLabel[b.shift] ?? b.shift}
                                            <div className="text-xs text-gray-500">
                                                {b.tanggal_periksa}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-sm">
                                            {b.keluhan_tags.length > 0 ? (
                                                <div className="flex max-w-xs flex-wrap items-center gap-1.5">
                                                    <span className="inline-flex items-center gap-1 text-[10px] font-medium text-[#2a78d6] dark:text-[#3987e5]">
                                                        <SparkleIcon />
                                                        AI Triage
                                                    </span>
                                                    {b.keluhan_tags.map((tag, idx) => (
                                                        <span
                                                            key={idx}
                                                            className="rounded-md bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-300"
                                                        >
                                                            {tag}
                                                        </span>
                                                    ))}
                                                </div>
                                            ) : (
                                                <span className="text-gray-400">-</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-sm">
                                            <span
                                                className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ${statusStyle[b.status] ?? ''}`}
                                            >
                                                <span className="h-1.5 w-1.5 rounded-full bg-current" />
                                                {statusLabel[b.status] ?? b.status}
                                            </span>
                                        </td>
                                        <td className="whitespace-nowrap px-4 py-3 text-sm">
                                            <div className="flex items-center gap-2">
                                                {b.chat_session_id && (
                                                    <button
                                                        type="button"
                                                        onClick={() => setOpenChatSessionId(b.chat_session_id)}
                                                        title="Lihat transkrip chat"
                                                        className="rounded-full p-1.5 text-gray-500 hover:bg-gray-100 hover:text-[#2a78d6] dark:text-gray-400 dark:hover:bg-gray-800"
                                                    >
                                                        <ChatIcon />
                                                    </button>
                                                )}
                                                {b.no_hp && (
                                                    <a
                                                        href={`tel:${b.no_hp}`}
                                                        title="Hubungi pasien"
                                                        className="rounded-full p-1.5 text-gray-500 hover:bg-gray-100 hover:text-[#2a78d6] dark:text-gray-400 dark:hover:bg-gray-800"
                                                    >
                                                        <PhoneIcon />
                                                    </a>
                                                )}
                                            </div>
                                            <div className="mt-1.5 space-x-2">
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
                                            </div>
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

            <ChatTranscriptModal
                chatSessionId={openChatSessionId}
                onClose={() => setOpenChatSessionId(null)}
            />
        </PreLayananLayout>
    );
}

function ChatIcon() {
    return (
        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={1.75} stroke="currentColor">
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375m-13.5 8.25l2.03-3.045A9.717 9.717 0 013 12c0-4.97 4.03-9 9-9s9 4.03 9 9-4.03 9-9 9a9.717 9.717 0 01-5.205-1.505L3 21z"
            />
        </svg>
    );
}

function PhoneIcon() {
    return (
        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={1.75} stroke="currentColor">
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M2.25 6.75c0 8.284 6.716 15 15 15h1.5a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106a2.25 2.25 0 00-2.36.86l-.667.994a1.5 1.5 0 01-1.634.586c-1.966-.588-4.29-2.912-4.878-4.878a1.5 1.5 0 01.586-1.634l.994-.667a2.25 2.25 0 00.86-2.36L7.72 3.352a1.125 1.125 0 00-1.091-.852H5.25A2.25 2.25 0 003 4.75v2z"
            />
        </svg>
    );
}

function SparkleIcon() {
    return (
        <svg className="h-3 w-3" fill="currentColor" viewBox="0 0 24 24">
            <path d="M9.937 15.5A2 2 0 018.5 14.063l-.437-1.62a1 1 0 00-.706-.706l-1.62-.437a2 2 0 010-3.86l1.62-.437a1 1 0 00.706-.706l.437-1.62a2 2 0 013.86 0l.437 1.62a1 1 0 00.706.706l1.62.437a2 2 0 010 3.86l-1.62.437a1 1 0 00-.706.706l-.437 1.62a2 2 0 01-1.437 1.437z" />
        </svg>
    );
}
