import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

export default function ComingSoon({ title, description }) {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    {title}
                </h2>
            }
        >
            <Head title={title} />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="flex flex-col items-center justify-center rounded-xl bg-white p-16 text-center shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                        <span className="flex h-14 w-14 items-center justify-center rounded-full bg-[#2a78d6]/10 dark:bg-[#3987e5]/15">
                            <svg
                                className="h-7 w-7 text-[#2a78d6] dark:text-[#3987e5]"
                                fill="none"
                                viewBox="0 0 24 24"
                                strokeWidth={1.5}
                                stroke="currentColor"
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    d="M12 6v6l4 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                />
                            </svg>
                        </span>
                        <h3 className="mt-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                            {title} &mdash; Segera Hadir
                        </h3>
                        <p className="mt-2 max-w-md text-sm text-gray-500 dark:text-gray-400">
                            {description}
                        </p>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
