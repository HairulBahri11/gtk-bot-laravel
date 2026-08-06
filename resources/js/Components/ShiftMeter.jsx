// Meter (ratio-against-limit) per shift - satu hue sekuensial (biru),
// track abu-abu netral, ujung membulat, label langsung di atas bar.
export default function ShiftMeter({ label, timeRange, note, used, total }) {
    const pct = total > 0 ? Math.round((used / total) * 100) : 0;
    const clampedPct = Math.min(100, pct);

    return (
        <div>
            <div className="mb-1.5 flex items-baseline justify-between text-sm">
                <span className="font-medium text-gray-700 dark:text-gray-300">
                    {label}
                    {timeRange && (
                        <span className="font-normal text-gray-400 dark:text-gray-500">
                            {' '}
                            · {timeRange}
                        </span>
                    )}
                </span>
                <span className="tabular-nums text-gray-500 dark:text-gray-400">
                    {used}/{total} ({pct}%)
                </span>
            </div>
            <div className="h-2.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                <div
                    className="h-full rounded-full bg-[#2a78d6] transition-[width] dark:bg-[#3987e5]"
                    style={{ width: `${clampedPct}%` }}
                />
            </div>
            {note && (
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {note}
                </p>
            )}
        </div>
    );
}
