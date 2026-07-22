import ApplicationLogo from '@/Components/ApplicationLogo';
import { Link } from '@inertiajs/react';

export default function GuestLayout({ children }) {
    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-gray-50 px-4 py-10 dark:bg-gray-950">
            <div className="flex flex-col items-center gap-2">
                <Link href="/">
                    <ApplicationLogo className="h-14 w-14 fill-current text-[#2a78d6] dark:text-[#3987e5]" />
                </Link>
                <span className="text-sm font-semibold tracking-tight text-gray-700 dark:text-gray-300">
                    Graha Tumbuh Kembang
                </span>
            </div>

            <div className="mt-6 w-full overflow-hidden rounded-xl bg-white px-6 py-6 shadow-sm ring-1 ring-gray-200/80 sm:max-w-md dark:bg-gray-900 dark:ring-gray-800">
                {children}
            </div>
        </div>
    );
}
