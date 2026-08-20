import ApplicationLogo from '@/Components/ApplicationLogo';
import Dropdown from '@/Components/Dropdown';
import NavLink from '@/Components/NavLink';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import toast, { Toaster } from 'react-hot-toast';

export default function AuthenticatedLayout({ header, children }) {
    const user = usePage().props.auth.user;

    const [showingNavigationDropdown, setShowingNavigationDropdown] =
        useState(false);

    // Pakai event global router.on('success') (bukan useEffect yang
    // depends on usePage().props.flash) - flash session Laravel HANYA
    // hidup satu request, tapi kalau dua aksi berturut-turut menghasilkan
    // pesan yang PERSIS sama (mis. "Booking dibatalkan." dua kali),
    // dependency array useEffect tidak akan mendeteksi "perubahan" karena
    // string-nya identik, sehingga toast kedua tidak muncul. Event ini
    // fire di SETIAP visit Inertia selesai terlepas dari isi propsnya.
    useEffect(() => {
        return router.on('success', (event) => {
            const flash = event.detail.page.props.flash;
            if (flash?.success) toast.success(flash.success);
            if (flash?.error) toast.error(flash.error);
        });
    }, []);

    return (
        <div className="min-h-screen bg-gray-50 dark:bg-gray-950">
            <Toaster
                position="top-right"
                toastOptions={{
                    duration: 4000,
                    className:
                        'rounded-xl bg-white text-sm font-medium text-gray-900 shadow-lg ring-1 ring-gray-200/70 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-800',
                    success: {
                        iconTheme: { primary: '#16a34a', secondary: '#fff' },
                    },
                    error: {
                        iconTheme: { primary: '#dc2626', secondary: '#fff' },
                    },
                }}
            />
            <nav className="sticky top-0 z-30 border-b border-gray-200/80 bg-white/90 backdrop-blur-sm dark:border-gray-800 dark:bg-gray-900/90">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 justify-between">
                        <div className="flex">
                            <div className="flex shrink-0 items-center gap-2">
                                <Link href="/" className="flex items-center gap-2">
                                    <ApplicationLogo className="block h-8 w-auto fill-current text-[#2a78d6] dark:text-[#3987e5]" />
                                    <span className="hidden text-sm font-semibold tracking-tight text-gray-800 dark:text-gray-100 sm:block">
                                        Graha Tumbuh Kembang
                                    </span>
                                </Link>
                            </div>

                            <div className="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                                <NavLink
                                    href={route('dashboard')}
                                    active={route().current('dashboard')}
                                >
                                    Overview
                                </NavLink>
                                <NavLink
                                    href={route('jadwal.index')}
                                    active={route().current('jadwal.*')}
                                >
                                    Jadwal Dokter
                                </NavLink>
                                {user.role !== 'dokter' && (
                                    <NavLink
                                        href={route('poliklinik.index')}
                                        active={route().current('poliklinik.*')}
                                    >
                                        Poliklinik
                                    </NavLink>
                                )}
                                <NavLink
                                    href={route('kuota.index')}
                                    active={
                                        route().current('kuota.*') ||
                                        route().current('antrean.*') ||
                                        route().current('monitoring.*')
                                    }
                                >
                                    Pre-Layanan
                                </NavLink>
                                <NavLink
                                    href={route('layanan.index')}
                                    active={route().current('layanan.index')}
                                >
                                    Layanan
                                </NavLink>
                                <NavLink
                                    href={route('post-layanan.index')}
                                    active={route().current(
                                        'post-layanan.index',
                                    )}
                                >
                                    Post-Layanan
                                </NavLink>
                            </div>
                        </div>

                        <div className="hidden sm:ms-6 sm:flex sm:items-center">
                            <div className="relative ms-3">
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <span className="inline-flex rounded-full">
                                            <button
                                                type="button"
                                                className="inline-flex items-center gap-2 rounded-full border border-transparent py-1.5 pe-3 ps-1.5 text-sm font-medium leading-4 text-gray-600 transition-colors duration-150 ease-in-out hover:bg-gray-100 focus:outline-none dark:text-gray-300 dark:hover:bg-gray-800"
                                            >
                                                <span className="flex h-7 w-7 items-center justify-center rounded-full bg-[#2a78d6]/10 text-xs font-semibold text-[#2a78d6] dark:bg-[#3987e5]/15 dark:text-[#3987e5]">
                                                    {user.name
                                                        ?.charAt(0)
                                                        ?.toUpperCase()}
                                                </span>
                                                {user.name}

                                                <svg
                                                    className="-me-0.5 h-4 w-4 text-gray-400"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path
                                                        fillRule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clipRule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </span>
                                    </Dropdown.Trigger>

                                    <Dropdown.Content>
                                        <Dropdown.Link
                                            href={route('profile.edit')}
                                        >
                                            Profile
                                        </Dropdown.Link>
                                        <Dropdown.Link
                                            href={route('logout')}
                                            method="post"
                                            as="button"
                                        >
                                            Log Out
                                        </Dropdown.Link>
                                    </Dropdown.Content>
                                </Dropdown>
                            </div>
                        </div>

                        <div className="-me-2 flex items-center sm:hidden">
                            <button
                                onClick={() =>
                                    setShowingNavigationDropdown(
                                        (previousState) => !previousState,
                                    )
                                }
                                className="inline-flex items-center justify-center rounded-lg p-2 text-gray-400 transition-colors duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-400 dark:focus:bg-gray-800 dark:focus:text-gray-400"
                            >
                                <svg
                                    className="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        className={
                                            !showingNavigationDropdown
                                                ? 'inline-flex'
                                                : 'hidden'
                                        }
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        className={
                                            showingNavigationDropdown
                                                ? 'inline-flex'
                                                : 'hidden'
                                        }
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div
                    className={
                        (showingNavigationDropdown ? 'block' : 'hidden') +
                        ' sm:hidden'
                    }
                >
                    <div className="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink
                            href={route('dashboard')}
                            active={route().current('dashboard')}
                        >
                            Overview
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            href={route('jadwal.index')}
                            active={route().current('jadwal.*')}
                        >
                            Jadwal Dokter
                        </ResponsiveNavLink>
                        {user.role !== 'dokter' && (
                            <ResponsiveNavLink
                                href={route('poliklinik.index')}
                                active={route().current('poliklinik.*')}
                            >
                                Poliklinik
                            </ResponsiveNavLink>
                        )}
                        <ResponsiveNavLink
                            href={route('kuota.index')}
                            active={
                                route().current('kuota.*') ||
                                route().current('antrean.*') ||
                                route().current('monitoring.*')
                            }
                        >
                            Pre-Layanan
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            href={route('layanan.index')}
                            active={route().current('layanan.index')}
                        >
                            Layanan
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            href={route('post-layanan.index')}
                            active={route().current('post-layanan.index')}
                        >
                            Post-Layanan
                        </ResponsiveNavLink>
                    </div>

                    <div className="border-t border-gray-200 pb-1 pt-4 dark:border-gray-800">
                        <div className="px-4">
                            <div className="text-base font-medium text-gray-800 dark:text-gray-200">
                                {user.name}
                            </div>
                            <div className="text-sm font-medium text-gray-500">
                                {user.email}
                            </div>
                        </div>

                        <div className="mt-3 space-y-1">
                            <ResponsiveNavLink href={route('profile.edit')}>
                                Profile
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                method="post"
                                href={route('logout')}
                                as="button"
                            >
                                Log Out
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            {header && (
                <header className="border-b border-gray-200/80 bg-white dark:border-gray-800 dark:bg-gray-900">
                    <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                        {header}
                    </div>
                </header>
            )}

            <main>{children}</main>
        </div>
    );
}
