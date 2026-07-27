import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import StatCard from "@/Components/StatCard";
import ShiftMeter from "@/Components/ShiftMeter";
import WeeklyTrendChart from "@/Components/WeeklyTrendChart";
import { Head, Link } from "@inertiajs/react";

const shiftLabel = { pagi: "Pagi", sore: "Sore", malam: "Malam" };

function noShowColor(rate) {
    if (rate >= 25) return "critical";
    if (rate >= 10) return "warning";

    return "good";
}

function kuotaColor(sisa) {
    if (sisa <= 0) return "critical";
    if (sisa < 5) return "warning";

    return "good";
}

export default function Dashboard({ stats, shiftToday, weeklyTrend }) {
    const today = new Date().toLocaleDateString("id-ID", {
        weekday: "long",
        day: "numeric",
        month: "long",
        year: "numeric",
    });

    return (
        <AuthenticatedLayout>
            <Head title="Overview" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div className="overflow-hidden rounded-xl bg-gradient-to-br from-[#2a78d6] to-[#1d5ba8] px-6 py-5 text-white shadow-sm dark:from-[#3987e5] dark:to-[#255f9e]">
                        <h3 className="text-lg font-semibold">
                            Sistem AI Pre-Layanan &mdash; Graha Tumbuh Kembang
                        </h3>
                        <p className="text-sm text-white/80">{today}</p>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard
                            icon="calendar"
                            color="blue"
                            label="Kunjungan Hari Ini"
                            value={stats.bookings_today}
                        />
                        <StatCard
                            icon="clock"
                            color="aqua"
                            label="Antrean Aktif"
                            value={stats.active_queue}
                        />
                        <StatCard
                            icon="check"
                            color={kuotaColor(stats.kuota_tersisa_hari_ini)}
                            label="Kuota Tersisa Hari Ini"
                            value={stats.kuota_tersisa_hari_ini}
                        />
                        <StatCard
                            icon="alert"
                            color={noShowColor(stats.no_show_rate)}
                            label="Tingkat No-Show (30 hari)"
                            value={stats.no_show_rate}
                            suffix="%"
                        />
                    </div>

                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                        <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800 lg:col-span-2">
                            <h3 className="mb-4 text-sm font-semibold text-gray-900 dark:text-gray-100">
                                Tren Kunjungan 7 Hari Terakhir
                            </h3>
                            {weeklyTrend.every((d) => d.count === 0) ? (
                                <p className="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Belum ada data kunjungan.
                                </p>
                            ) : (
                                <WeeklyTrendChart data={weeklyTrend} />
                            )}
                        </div>

                        <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                            <h3 className="mb-4 text-sm font-semibold text-gray-900 dark:text-gray-100">
                                Kuota Shift Hari Ini
                            </h3>
                            {shiftToday.length === 0 ? (
                                <p className="text-sm text-gray-500 dark:text-gray-400">
                                    Tidak ada jadwal hari ini.
                                </p>
                            ) : (
                                <div className="space-y-4">
                                    {shiftToday.map((s) => (
                                        <ShiftMeter
                                            key={s.shift}
                                            label={
                                                shiftLabel[s.shift] ?? s.shift
                                            }
                                            used={s.used}
                                            total={s.total}
                                        />
                                    ))}
                                </div>
                            )}
                            <Link
                                href={route("kuota.index")}
                                className="mt-4 inline-block text-sm text-[#2a78d6] hover:underline dark:text-[#3987e5]"
                            >
                                Lihat detail kuota &rarr;
                            </Link>
                        </div>
                    </div>

                    <div className="overflow-hidden rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                        <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">
                            Navigasi Cepat
                        </h3>
                        <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">
                            Buka tab <strong>Pre-Layanan</strong> untuk memantau
                            kuota shift dokter, mengelola
                            booking/waitlist/No-Show, dan melihat status
                            percakapan WhatsApp & rekam medis pasien.
                        </p>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
