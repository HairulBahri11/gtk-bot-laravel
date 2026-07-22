import PreLayananLayout from '@/Layouts/PreLayananLayout';
import { Head, Link } from '@inertiajs/react';
import { useEffect, useMemo, useRef } from 'react';

const stateLabel = {
    STATE_1_PENGUMPULAN_DATA: 'Pengumpulan Data',
    STATE_2_KONFIRMASI: 'Konfirmasi',
    STATE_3_DONE: 'Selesai',
};

const bookingStatusStyle = {
    waitlist: 'bg-yellow-50 text-yellow-700 ring-1 ring-yellow-600/20 dark:bg-yellow-900/40 dark:text-yellow-300 dark:ring-yellow-500/20',
    booked: 'bg-blue-50 text-blue-700 ring-1 ring-blue-600/20 dark:bg-blue-900/40 dark:text-blue-300 dark:ring-blue-500/20',
    confirmed: 'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-600/20 dark:bg-indigo-900/40 dark:text-indigo-300 dark:ring-indigo-500/20',
    arrived: 'bg-green-50 text-green-700 ring-1 ring-green-600/20 dark:bg-green-900/40 dark:text-green-300 dark:ring-green-500/20',
    no_show: 'bg-red-50 text-red-700 ring-1 ring-red-600/20 dark:bg-red-900/40 dark:text-red-300 dark:ring-red-500/20',
    cancelled: 'bg-gray-100 text-gray-600 ring-1 ring-gray-400/20 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-600/30',
    rescheduled: 'bg-purple-50 text-purple-700 ring-1 ring-purple-600/20 dark:bg-purple-900/40 dark:text-purple-300 dark:ring-purple-500/20',
};

// Backend mengirim "YYYY-MM-DD HH:mm:ss" (bukan ISO 8601 murni) - ganti
// spasi jadi "T" supaya parsing Date() konsisten di semua browser.
function toDate(dateStr) {
    return new Date(dateStr.replace(' ', 'T'));
}

function formatDateLabel(dateStr) {
    return toDate(dateStr).toLocaleDateString('id-ID', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

function formatTime(dateStr) {
    return toDate(dateStr).toLocaleTimeString('id-ID', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

// Kelompokkan pesan per hari, dan tandai giliran pertama tiap blok
// pengirim berurutan supaya label pengirim tidak diulang tiap bubble.
function groupMessages(messages) {
    const groups = [];
    let currentDay = null;
    let currentGroup = null;
    let lastDirection = null;

    for (const m of messages) {
        const day = m.created_at.slice(0, 10);

        if (day !== currentDay) {
            currentDay = day;
            currentGroup = { day, items: [] };
            groups.push(currentGroup);
            lastDirection = null;
        }

        currentGroup.items.push({
            ...m,
            isFirstOfBlock: m.direction !== lastDirection,
        });
        lastDirection = m.direction;
    }

    return groups;
}

export default function MonitoringShow({ session }) {
    const scrollRef = useRef(null);
    const dayGroups = useMemo(
        () => groupMessages(session.messages),
        [session.messages],
    );

    useEffect(() => {
        if (scrollRef.current) {
            scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
        }
    }, [session.messages.length]);

    return (
        <PreLayananLayout header={`Monitoring: ${session.chat_id}`}>
            <Head title={`Monitoring - ${session.chat_id}`} />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <Link
                        href={route('monitoring.index')}
                        className="text-sm text-[#2a78d6] hover:underline dark:text-[#3987e5]"
                    >
                        &larr; Kembali ke daftar
                    </Link>
                </div>

                <div className="mx-auto mt-4 grid max-w-7xl grid-cols-1 gap-6 sm:px-6 lg:grid-cols-3 lg:px-8">
                    <div className="space-y-6 lg:col-span-1">
                        <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                            <h3 className="text-sm font-medium text-gray-500 dark:text-gray-400">
                                Status
                            </h3>
                            <p className="mt-1 text-lg font-semibold text-gray-900 dark:text-gray-100">
                                {stateLabel[session.state] ?? session.state}
                            </p>
                        </div>

                        {session.patient && (
                            <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                                <h3 className="text-sm font-medium text-gray-500 dark:text-gray-400">
                                    Pasien Saat Ini Diproses
                                </h3>
                                <p className="mt-0.5 text-xs text-gray-400">
                                    Nomor WA ini bisa mendaftarkan lebih dari
                                    satu anak - lihat riwayat lengkap di bawah.
                                </p>
                                <dl className="mt-2 space-y-1 text-sm text-gray-900 dark:text-gray-100">
                                    <div>
                                        <dt className="inline text-gray-500">
                                            No. RM:{' '}
                                        </dt>
                                        <dd className="inline">
                                            {session.patient.no_rm}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="inline text-gray-500">
                                            Nama:{' '}
                                        </dt>
                                        <dd className="inline">
                                            {session.patient.nama}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="inline text-gray-500">
                                            Tanggal Lahir:{' '}
                                        </dt>
                                        <dd className="inline">
                                            {session.patient.tanggal_lahir}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="inline text-gray-500">
                                            Nama Ibu:{' '}
                                        </dt>
                                        <dd className="inline">
                                            {
                                                session.patient
                                                    .nama_ibu_kandung
                                            }
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        )}

                        <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                            <h3 className="text-sm font-medium text-gray-500 dark:text-gray-400">
                                Riwayat Pendaftaran &amp; Booking
                            </h3>
                            {session.bookings.length === 0 && (
                                <p className="mt-2 text-sm text-gray-500">
                                    Belum ada booking.
                                </p>
                            )}
                            <ul className="mt-2 space-y-3">
                                {session.bookings.map((b) => (
                                    <li
                                        key={b.id}
                                        className="rounded-lg border border-gray-200 p-3 text-sm transition-colors hover:border-gray-300 dark:border-gray-800 dark:hover:border-gray-700"
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="font-medium text-gray-900 dark:text-gray-100">
                                                {b.nama_pasien ?? '-'}
                                                {b.no_rm && (
                                                    <span className="font-normal text-gray-500 dark:text-gray-400">
                                                        {' '}
                                                        (RM: {b.no_rm})
                                                    </span>
                                                )}
                                            </div>
                                            <span
                                                className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ${bookingStatusStyle[b.status] ?? ''}`}
                                            >
                                                <span className="h-1.5 w-1.5 rounded-full bg-current" />
                                                {b.status}
                                            </span>
                                        </div>
                                        <div className="mt-1 text-gray-700 dark:text-gray-300">
                                            {b.poliklinik} &middot; {b.dokter}
                                        </div>
                                        <div className="mt-1 text-gray-500">
                                            {b.tanggal_periksa} ({b.shift})
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>

                    <div className="flex h-[calc(100vh-260px)] min-h-[520px] flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800 lg:col-span-2">
                        <div className="flex shrink-0 items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-800">
                            <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                Transkrip Percakapan
                            </h3>
                            <span className="text-xs text-gray-500 dark:text-gray-400">
                                {session.messages.length} pesan
                            </span>
                        </div>

                        <div
                            ref={scrollRef}
                            className="flex-1 space-y-4 overflow-y-auto bg-gray-50/50 px-6 py-4 dark:bg-gray-950/40"
                        >
                            {session.messages.length === 0 && (
                                <p className="py-8 text-center text-sm text-gray-500">
                                    Belum ada pesan.
                                </p>
                            )}

                            {dayGroups.map((group) => (
                                <div key={group.day} className="space-y-3">
                                    <div className="sticky top-0 z-10 flex justify-center py-1">
                                        <span className="rounded-full bg-gray-200/80 px-3 py-1 text-xs font-medium text-gray-600 backdrop-blur dark:bg-gray-700/80 dark:text-gray-300">
                                            {formatDateLabel(group.day)}
                                        </span>
                                    </div>

                                    {group.items.map((m, i) => (
                                        <div
                                            key={i}
                                            className={`flex flex-col ${m.direction === 'out' ? 'items-end' : 'items-start'}`}
                                        >
                                            {m.isFirstOfBlock && (
                                                <span className="mb-1 px-1 text-xs font-medium text-gray-400 dark:text-gray-500">
                                                    {m.direction === 'out'
                                                        ? 'AI Pre-Layanan'
                                                        : 'Orang Tua Pasien'}
                                                </span>
                                            )}
                                            <div
                                                className={`max-w-md rounded-2xl px-4 py-2.5 text-sm shadow-sm ${
                                                    m.direction === 'out'
                                                        ? 'rounded-tr-sm bg-[#2a78d6] text-white dark:bg-[#3987e5]'
                                                        : 'rounded-tl-sm bg-white text-gray-900 dark:bg-gray-700 dark:text-gray-100'
                                                }`}
                                            >
                                                <p className="whitespace-pre-wrap">
                                                    {m.message}
                                                </p>
                                                <p
                                                    className={`mt-1 text-right text-[11px] ${
                                                        m.direction === 'out'
                                                            ? 'text-white/70'
                                                            : 'text-gray-400'
                                                    }`}
                                                >
                                                    {formatTime(m.created_at)}
                                                </p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </PreLayananLayout>
    );
}
