// Horizontal grouped bar chart - satu baris kategori (label bebas dari
// caller, mis. nama poliklinik) berisi DUA bar terpisah: Kapasitas (abu-abu,
// konteks) dan Terpakai (biru aksen, data utama), masing-masing dengan ujung
// membulat & baseline rata kiri. Skala X sama untuk semua baris (satu axis).
const BAR_THICKNESS = 10; // px, di bawah batas 24px pada spesifikasi mark
const BAR_GAP = 2; // px, spacer antar-bar dalam satu grup

function niceMax(value) {
    if (value <= 10) return 10;
    const magnitude = Math.pow(10, Math.floor(Math.log10(value)));
    const normalized = value / magnitude;
    const step = normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10;
    return step * magnitude;
}

export default function ShiftUtilizationChart({ data }) {
    const max = niceMax(Math.max(1, ...data.map((d) => d.total)));
    const ticks = [0, max * 0.25, max * 0.5, max * 0.75, max];

    return (
        <div>
            <div className="mb-4 flex items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
                <span className="flex items-center gap-1.5">
                    <span className="h-2.5 w-2.5 rounded-sm bg-[#cde2fb] dark:bg-[#184f95]" />
                    Kapasitas
                </span>
                <span className="flex items-center gap-1.5">
                    <span className="h-2.5 w-2.5 rounded-sm bg-[#2a78d6] dark:bg-[#3987e5]" />
                    Terpakai
                </span>
            </div>

            <div className="space-y-5">
                {data.map((d) => {
                    const pct = d.total > 0 ? Math.round((d.used / d.total) * 100) : 0;

                    return (
                        <div key={d.label}>
                            <div
                                className="mb-1.5 truncate text-sm font-medium text-gray-700 dark:text-gray-300"
                                title={d.label}
                            >
                                {d.label}
                            </div>
                            <div className="flex items-center gap-3">
                                <div className="relative flex-1">
                                    {/* Gridline hairline, rata dengan skala max bersama */}
                                    <div className="pointer-events-none absolute inset-0 flex justify-between">
                                        {ticks.map((t, i) => (
                                            <span
                                                key={i}
                                                className="w-px bg-gray-100 dark:bg-gray-800"
                                            />
                                        ))}
                                    </div>
                                    <div
                                        className="relative"
                                        style={{ gap: `${BAR_GAP}px`, display: 'flex', flexDirection: 'column' }}
                                    >
                                        <div
                                            className="rounded-r-[4px] bg-[#cde2fb] dark:bg-[#184f95]"
                                            style={{
                                                height: `${BAR_THICKNESS}px`,
                                                width: `${(d.total / max) * 100}%`,
                                            }}
                                        />
                                        <div
                                            className="rounded-r-[4px] bg-[#2a78d6] transition-[width] dark:bg-[#3987e5]"
                                            style={{
                                                height: `${BAR_THICKNESS}px`,
                                                width: `${(d.used / max) * 100}%`,
                                            }}
                                        />
                                    </div>
                                </div>
                                <span className="w-28 shrink-0 text-right text-sm tabular-nums text-gray-600 dark:text-gray-300">
                                    {d.used}/{d.total} ({pct}%)
                                </span>
                            </div>
                        </div>
                    );
                })}
            </div>

            <div className="mt-2 flex items-center gap-3">
                <div className="flex flex-1 justify-between text-xs text-gray-400 dark:text-gray-500">
                    {ticks.map((t, i) => (
                        <span key={i}>{Math.round(t)}</span>
                    ))}
                </div>
                <span className="w-28 shrink-0" />
            </div>
        </div>
    );
}
