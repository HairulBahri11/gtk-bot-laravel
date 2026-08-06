const ICON_PATHS = {
    calendar:
        'M6.75 3v2.25M17.25 3v2.25M3.75 7.5h16.5M4.5 6h15a.75.75 0 01.75.75v13.5a.75.75 0 01-.75.75h-15a.75.75 0 01-.75-.75V6.75A.75.75 0 014.5 6z',
    clock: 'M12 6v6l4 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    check: 'M9 12.75l2.25 2.25L15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    alert: 'M12 9v3.75m0 3h.008v.008H12v-.008zM10.29 3.86l-8.18 14.18A1.5 1.5 0 003.5 20.5h17a1.5 1.5 0 001.39-2.46L13.71 3.86a1.5 1.5 0 00-2.42 0z',
};

// Kelas literal (bukan hasil template string) supaya tetap terdeteksi Tailwind JIT.
const ROLE_CLASSES = {
    blue: {
        icon: 'text-[#2a78d6] dark:text-[#3987e5]',
        wash: 'bg-[#2a78d6]/10 dark:bg-[#3987e5]/15',
    },
    aqua: {
        icon: 'text-[#1baf7a] dark:text-[#199e70]',
        wash: 'bg-[#1baf7a]/10 dark:bg-[#199e70]/15',
    },
    good: {
        icon: 'text-[#0ca30c] dark:text-[#0ca30c]',
        wash: 'bg-[#0ca30c]/10 dark:bg-[#0ca30c]/15',
    },
    warning: {
        icon: 'text-[#fab219] dark:text-[#fab219]',
        wash: 'bg-[#fab219]/10 dark:bg-[#fab219]/15',
    },
    critical: {
        icon: 'text-[#d03b3b] dark:text-[#d03b3b]',
        wash: 'bg-[#d03b3b]/10 dark:bg-[#d03b3b]/15',
    },
};

export default function StatCard({ icon, color = 'blue', label, value, suffix, subtitle }) {
    const roleClasses = ROLE_CLASSES[color] ?? ROLE_CLASSES.blue;

    return (
        <div className="overflow-hidden rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200/70 transition-shadow hover:shadow-md dark:bg-gray-900 dark:ring-gray-800">
            <div className="flex items-start justify-between gap-3">
                <div className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {label}
                </div>
                <span
                    className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${roleClasses.wash}`}
                >
                    <svg
                        className={`h-5 w-5 ${roleClasses.icon}`}
                        fill="none"
                        viewBox="0 0 24 24"
                        strokeWidth={1.75}
                        stroke="currentColor"
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d={ICON_PATHS[icon]}
                        />
                    </svg>
                </span>
            </div>
            <div className="mt-3 text-3xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                {value}
                {suffix && (
                    <span className="ms-1 text-base font-normal text-gray-500">
                        {suffix}
                    </span>
                )}
            </div>
            {subtitle && (
                <div className="mt-1 truncate text-sm text-gray-500 dark:text-gray-400" title={subtitle}>
                    {subtitle}
                </div>
            )}
        </div>
    );
}
