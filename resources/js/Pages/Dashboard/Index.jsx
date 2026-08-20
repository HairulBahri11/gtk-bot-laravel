import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import StatCard from "@/Components/StatCard";
import ShiftMeter from "@/Components/ShiftMeter";
import ShiftUtilizationChart from "@/Components/ShiftUtilizationChart";
import { Head, Link } from "@inertiajs/react";

const SHIFT_LABEL = { pagi: "Pagi", sore: "Sore", malam: "Malam" };

function batalColor(count) {
    return count > 0 ? "warning" : "good";
}

export default function Dashboard({ clinic, stats, quotaByPoliklinik }) {
    const today = new Date().toLocaleDateString("id-ID", {
        weekday: "long",
        day: "numeric",
        month: "long",
        year: "numeric",
    });

    // Chart utilisasi dihitung per poliklinik (dijumlahkan lintas shift),
    // bukan per shift - supaya konsisten dengan breakdown detail di bawahnya
    // yang juga dikelompokkan per poliklinik.
    const chartData = quotaByPoliklinik.map((poli) => ({
        label: poli.poliklinik,
        total: poli.shifts.reduce((sum, s) => sum + s.total, 0),
        used: poli.shifts.reduce((sum, s) => sum + s.used, 0),
    }));

    return (
        <AuthenticatedLayout>
            <Head title="Overview" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div className="flex flex-col gap-4 overflow-hidden rounded-xl bg-gradient-to-br from-[#2a78d6] to-[#1d5ba8] px-6 py-5 text-white shadow-sm dark:from-[#3987e5] dark:to-[#255f9e] sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <h3 className="text-lg font-semibold">
                                    Graha Tumbuh Kembang Jombang
                                </h3>
                            </div>
                            <p className="text-sm text-white/80">
                                {clinic.doctor_name &&
                                    `dr. ${clinic.doctor_name} · `}
                                {today}
                            </p>
                        </div>
                        <div className="flex items-center gap-3">
                            <span
                                className={`flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium ${
                                    clinic.live_sync
                                        ? "bg-white/15 text-white"
                                        : "bg-black/15 text-white/70"
                                }`}
                                title={
                                    clinic.live_sync
                                        ? "Data kuota tersinkron dalam 15 menit terakhir"
                                        : "Sinkronisasi kuota tertunda"
                                }
                            >
                                <span
                                    className={`h-1.5 w-1.5 rounded-full ${
                                        clinic.live_sync
                                            ? "bg-emerald-300"
                                            : "bg-white/50"
                                    }`}
                                />
                                {clinic.live_sync
                                    ? "Live sync"
                                    : "Sync tertunda"}
                            </span>
                            <span
                                className="relative flex h-8 w-8 items-center justify-center rounded-full bg-white/15"
                                title={`${stats.batal_no_show_hari_ini} batal/no-show hari ini`}
                            >
                                <svg
                                    className="h-4 w-4"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    strokeWidth={1.75}
                                    stroke="currentColor"
                                >
                                    <path
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"
                                    />
                                </svg>
                                {stats.batal_no_show_hari_ini > 0 && (
                                    <span className="absolute -right-1 -top-1 flex h-4 w-4 items-center justify-center rounded-full bg-[#d03b3b] text-[10px] font-semibold text-white">
                                        {stats.batal_no_show_hari_ini}
                                    </span>
                                )}
                            </span>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <StatCard
                            icon="calendar"
                            color="blue"
                            label="Total Pasien Hari Ini"
                            value={stats.total_pasien_hari_ini}
                            subtitle={`${stats.dikonfirmasi_hari_ini} dikonfirmasi · ${stats.sudah_datang_hari_ini} sudah datang`}
                        />
                        <StatCard
                            icon="clock"
                            color="aqua"
                            label="Menunggu / Terjadwal"
                            value={stats.menunggu_terjadwal_hari_ini}
                            subtitle="Termasuk yang belum datang"
                        />
                        <StatCard
                            icon="alert"
                            color={batalColor(stats.batal_no_show_hari_ini)}
                            label="Batal / No-Show"
                            value={stats.batal_no_show_hari_ini}
                            subtitle="Slot bisa direalokasi"
                        />
                    </div>

                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                        <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800 lg:col-span-2">
                            <h3 className="mb-4 text-sm font-semibold text-gray-900 dark:text-gray-100">
                                Utilisasi Slot per Poliklinik
                            </h3>
                            {chartData.length === 0 ? (
                                <p className="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Tidak ada jadwal hari ini.
                                </p>
                            ) : (
                                <ShiftUtilizationChart data={chartData} />
                            )}
                        </div>

                        <div className="space-y-4">
                            <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                Aksi Cepat
                            </h3>

                            <AksiCepatCard
                                tone="blue"
                                title="Dynamic Slot Shifting"
                                description="Alihkan sisa slot yang jarang terpakai ke layanan dengan antrean lebih padat hari ini."
                                cta="Lihat rekomendasi"
                            />

                            <AksiCepatCard
                                tone="critical"
                                title="Emergency Reschedule"
                                description="Geser sisa antrean shift berjalan ke shift berikutnya & broadcast notifikasi ke pasien terdampak."
                                cta="Lihat opsi reschedule"
                            />
                        </div>
                    </div>

                    <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                        <div className="mb-4 flex items-center justify-between">
                            <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                Jadwal Kuota per Poliklinik Hari Ini
                            </h3>
                            <Link
                                href={route("kuota.index")}
                                className="text-sm text-[#2a78d6] hover:underline dark:text-[#3987e5]"
                            >
                                Lihat detail kuota &rarr;
                            </Link>
                        </div>

                        {quotaByPoliklinik.length === 0 ? (
                            <p className="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                Tidak ada jadwal poliklinik hari ini.
                            </p>
                        ) : (
                            <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                                {quotaByPoliklinik.map((poli) => (
                                    <div
                                        key={poli.poliklinik}
                                        className="rounded-lg border border-gray-100 p-4 dark:border-gray-800"
                                    >
                                        <h4 className="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                            {poli.poliklinik}
                                        </h4>
                                        <div className="mt-3 space-y-3">
                                            {poli.shifts.map((s) => (
                                                <ShiftMeter
                                                    key={s.shift}
                                                    label={
                                                        SHIFT_LABEL[s.shift] ??
                                                        s.shift
                                                    }
                                                    timeRange={s.time_range}
                                                    note={
                                                        s.dokter ||
                                                        "Dokter belum ditentukan"
                                                    }
                                                    used={s.used}
                                                    total={s.total}
                                                />
                                            ))}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function AksiCepatCard({ tone, title, description, cta }) {
    const toneClasses =
        tone === "critical"
            ? {
                  border: "border-[#d03b3b]/20 dark:border-[#d03b3b]/30",
                  bg: "bg-[#d03b3b]/5 dark:bg-[#d03b3b]/10",
                  title: "text-[#d03b3b]",
                  button: "bg-[#d03b3b] hover:bg-[#b73232] focus-visible:outline-[#d03b3b]",
              }
            : {
                  border: "border-[#2a78d6]/20 dark:border-[#3987e5]/30",
                  bg: "bg-[#2a78d6]/5 dark:bg-[#3987e5]/10",
                  title: "text-[#2a78d6] dark:text-[#3987e5]",
                  button: "bg-[#2a78d6] hover:bg-[#215fab] focus-visible:outline-[#2a78d6] dark:bg-[#3987e5]",
              };

    return (
        <div
            className={`rounded-xl border p-5 shadow-sm ${toneClasses.border} ${toneClasses.bg}`}
        >
            <h4 className={`text-sm font-semibold ${toneClasses.title}`}>
                {title}
            </h4>
            <p className="mt-1.5 text-sm text-gray-600 dark:text-gray-300">
                {description}
            </p>
            <button
                type="button"
                onClick={() =>
                    window.alert(
                        "Fitur ini akan segera hadir - rekomendasi masih berupa tampilan awal.",
                    )
                }
                className={`mt-4 w-full rounded-lg px-3 py-2 text-sm font-semibold text-white shadow-sm transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 ${toneClasses.button}`}
            >
                {cta}
            </button>
        </div>
    );
}
