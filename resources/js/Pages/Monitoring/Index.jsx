import PreLayananLayout from "@/Layouts/PreLayananLayout";
import { Head, Link, router } from "@inertiajs/react";
import { useState } from "react";

const stateLabel = {
    STATE_1_PENGUMPULAN_DATA: "Pengumpulan Data",
    STATE_2_KONFIRMASI: "Konfirmasi",
    STATE_3_DONE: "Selesai",
};

const statusLabel = {
    in_progress: "Sedang diproses",
    waitlist: "Waitlist",
    booked: "Booked",
    confirmed: "Confirmed",
    arrived: "Arrived",
    no_show: "No-Show",
    cancelled: "Dibatalkan",
    rescheduled: "Dijadwal ulang",
};

export default function MonitoringIndex({ sessions, filters }) {
    const [search, setSearch] = useState(filters.search ?? "");

    function applySearch(e) {
        e.preventDefault();
        router.get(route("monitoring.index"), search ? { search } : {}, {
            preserveState: true,
        });
    }

    return (
        <PreLayananLayout header="Monitoring Pasien">
            <Head title="Monitoring Pasien" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <form
                        onSubmit={applySearch}
                        className="flex items-end gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800"
                    >
                        <div className="flex-1">
                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Cari No. RM / nama
                            </label>
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm transition-colors focus:border-[#2a78d6] focus:ring-[#2a78d6] dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:focus:border-[#3987e5] dark:focus:ring-[#3987e5]"
                                placeholder="6281234567890 / 000099 / Budi"
                            />
                        </div>
                        <button
                            type="submit"
                            className="rounded-lg bg-[#2a78d6] px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-[#2568bd] dark:bg-[#3987e5] dark:hover:bg-[#2f74c9]"
                        >
                            Cari
                        </button>
                    </form>

                    <div className="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                        <table className="min-w-full divide-y divide-gray-100 dark:divide-gray-800">
                            <thead className="border-b border-gray-200 bg-gray-50/80 dark:border-gray-800 dark:bg-gray-800/40">
                                <tr>
                                    {[
                                        "Nomor WhatsApp",
                                        "Pasien Terdaftar",
                                        "State",
                                        "Pesan Terakhir",
                                        "",
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
                                {sessions.data.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="px-4 py-6 text-center text-sm text-gray-500"
                                        >
                                            Tidak ada percakapan ditemukan.
                                        </td>
                                    </tr>
                                )}
                                {sessions.data.map((s) => (
                                    <tr
                                        key={s.id}
                                        className="transition-colors hover:bg-gray-50/80 dark:hover:bg-gray-800/40"
                                    >
                                        <td className="px-4 py-3 align-top text-sm text-gray-900 dark:text-gray-100">
                                            {s.nomor_wa ?? (
                                                <span className="text-gray-400">
                                                    Nomor tidak terdeteksi
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 align-top text-sm text-gray-900 dark:text-gray-100">
                                            {s.patients.length === 0 ? (
                                                <span className="text-gray-400">
                                                    -
                                                </span>
                                            ) : (
                                                <ul className="space-y-1.5">
                                                    {s.patients.map((p, i) => (
                                                        <li key={i}>
                                                            <span className="font-medium">
                                                                {p.nama ?? "-"}
                                                            </span>
                                                            {p.no_rm && (
                                                                <span className="text-gray-500 dark:text-gray-400">
                                                                    {" "}
                                                                    (RM:{" "}
                                                                    {p.no_rm})
                                                                </span>
                                                            )}
                                                            {p.poliklinik && (
                                                                <span className="text-gray-500 dark:text-gray-400">
                                                                    {" "}
                                                                    ·{" "}
                                                                    {
                                                                        p.poliklinik
                                                                    }
                                                                </span>
                                                            )}
                                                            {p.status && (
                                                                <span className="ml-1.5 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                                                    {statusLabel[
                                                                        p.status
                                                                    ] ??
                                                                        p.status}
                                                                </span>
                                                            )}
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 align-top text-sm text-gray-900 dark:text-gray-100">
                                            {stateLabel[s.state] ?? s.state}
                                        </td>
                                        <td className="px-4 py-3 align-top text-sm text-gray-500 dark:text-gray-400">
                                            {s.last_message_at ?? "-"}
                                        </td>
                                        <td className="px-4 py-3 align-top text-sm">
                                            <Link
                                                href={route(
                                                    "monitoring.show",
                                                    s.id,
                                                )}
                                                className="font-medium text-[#2a78d6] hover:underline dark:text-[#3987e5]"
                                            >
                                                Lihat transkrip
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {sessions.links && (
                        <div className="flex flex-wrap gap-1">
                            {sessions.links.map((link, i) => (
                                <Link
                                    key={i}
                                    href={link.url ?? "#"}
                                    className={`rounded-lg px-3 py-1.5 text-sm transition-colors ${
                                        link.active
                                            ? "bg-[#2a78d6] text-white dark:bg-[#3987e5]"
                                            : "bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-800 dark:hover:bg-gray-800"
                                    } ${!link.url ? "pointer-events-none opacity-50" : ""}`}
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
