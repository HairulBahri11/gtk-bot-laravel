import ApplicationLogo from '@/Components/ApplicationLogo';
import { Head, Link } from '@inertiajs/react';

export default function Welcome({ auth, canLogin, canRegister }) {
    return (
        <>
            <Head title="Graha Tumbuh Kembang" />
            <div className="flex min-h-screen flex-col items-center justify-center bg-gray-50 px-6 dark:bg-gray-950">
                <div className="flex flex-col items-center gap-3 text-center">
                    <ApplicationLogo className="h-14 w-14 fill-current text-[#2a78d6] dark:text-[#3987e5]" />
                    <h1 className="text-2xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">
                        Graha Tumbuh Kembang
                    </h1>
                    <p className="max-w-sm text-sm text-gray-500 dark:text-gray-400">
                        Sistem AI Pre-Layanan &amp; manajemen antrean klinik
                        tumbuh kembang anak.
                    </p>
                </div>

                <div className="mt-8 flex items-center gap-3">
                    {auth?.user ? (
                        <Link
                            href={route('dashboard')}
                            className="inline-flex items-center justify-center rounded-lg bg-[#2a78d6] px-5 py-2.5 text-sm font-medium text-white shadow-sm transition-colors hover:bg-[#2568bd] dark:bg-[#3987e5] dark:hover:bg-[#2f74c9]"
                        >
                            Buka Dashboard
                        </Link>
                    ) : (
                        <>
                            {canLogin && (
                                <Link
                                    href={route('login')}
                                    className="inline-flex items-center justify-center rounded-lg bg-[#2a78d6] px-5 py-2.5 text-sm font-medium text-white shadow-sm transition-colors hover:bg-[#2568bd] dark:bg-[#3987e5] dark:hover:bg-[#2f74c9]"
                                >
                                    Masuk
                                </Link>
                            )}
                            {canRegister && (
                                <Link
                                    href={route('register')}
                                    className="inline-flex items-center justify-center rounded-lg border border-gray-200 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition-colors hover:border-gray-300 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:bg-gray-800"
                                >
                                    Daftar
                                </Link>
                            )}
                        </>
                    )}
                </div>
            </div>
        </>
    );
}
