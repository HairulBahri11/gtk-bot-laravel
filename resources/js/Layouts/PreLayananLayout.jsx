import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Link } from '@inertiajs/react';

const subTabs = [
    { name: 'Kuota', route: 'kuota.index', pattern: 'kuota.*' },
    { name: 'Antrean & Monitoring', route: 'antrean.index', pattern: 'antrean.*' },
    { name: 'Jadwal Dokter', route: 'jadwal.index', pattern: 'jadwal.*' },
];

export default function PreLayananLayout({ header, children }) {
    return (
        <AuthenticatedLayout
            header={
                <div className="space-y-4">
                    <div>
                        <p className="text-sm font-medium text-[#2a78d6] dark:text-[#3987e5]">
                            Pre-Layanan
                        </p>
                        <h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                            {header}
                        </h2>
                    </div>

                    <div className="flex gap-1 border-b border-gray-200 dark:border-gray-800">
                        {subTabs.map((tab) => {
                            const active = route().current(tab.pattern);

                            return (
                                <Link
                                    key={tab.route}
                                    href={route(tab.route)}
                                    className={`-mb-px rounded-t-md border-b-2 px-3 py-2 text-sm font-medium transition-colors ${
                                        active
                                            ? 'border-[#2a78d6] text-[#2a78d6] dark:border-[#3987e5] dark:text-[#3987e5]'
                                            : 'border-transparent text-gray-500 hover:border-gray-300 hover:bg-gray-50 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-800/60 dark:hover:text-gray-200'
                                    }`}
                                >
                                    {tab.name}
                                </Link>
                            );
                        })}
                    </div>
                </div>
            }
        >
            {children}
        </AuthenticatedLayout>
    );
}
