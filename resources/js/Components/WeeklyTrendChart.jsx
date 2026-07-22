// Column chart sederhana - satu hue sekuensial (biru), label nilai di atas
// setiap bar ("value on the cap"), ujung membulat, baseline persegi.
export default function WeeklyTrendChart({ data }) {
    const max = Math.max(1, ...data.map((d) => d.count));

    return (
        <div className="flex h-40 items-end gap-2">
            {data.map((d) => {
                const heightPct = (d.count / max) * 100;

                return (
                    <div
                        key={d.date}
                        className="flex flex-1 flex-col items-center gap-1"
                    >
                        <span className="text-xs font-medium tabular-nums text-gray-600 dark:text-gray-300">
                            {d.count}
                        </span>
                        <div className="flex h-28 w-full items-end justify-center">
                            <div
                                className="w-full max-w-[24px] rounded-t bg-[#2a78d6] dark:bg-[#3987e5]"
                                style={{
                                    height: `${d.count > 0 ? Math.max(heightPct, 4) : 0}%`,
                                }}
                            />
                        </div>
                        <span className="text-xs text-gray-500 dark:text-gray-400">
                            {d.label}
                        </span>
                    </div>
                );
            })}
        </div>
    );
}
