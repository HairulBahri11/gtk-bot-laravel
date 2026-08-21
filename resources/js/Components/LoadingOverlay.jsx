export default function LoadingOverlay({ show }) {
    if (!show) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/20 backdrop-blur-[1px] dark:bg-gray-950/50">
            <div className="flex items-center gap-3 rounded-xl bg-white px-5 py-4 shadow-lg ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                <svg
                    className="h-5 w-5 animate-spin text-[#2a78d6] dark:text-[#3987e5]"
                    viewBox="0 0 24 24"
                    fill="none"
                >
                    <circle
                        className="opacity-25"
                        cx="12"
                        cy="12"
                        r="10"
                        stroke="currentColor"
                        strokeWidth="4"
                    />
                    <path
                        className="opacity-75"
                        fill="currentColor"
                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                    />
                </svg>
                <span className="text-sm font-medium text-gray-700 dark:text-gray-200">
                    Memuat halaman...
                </span>
            </div>
        </div>
    );
}
