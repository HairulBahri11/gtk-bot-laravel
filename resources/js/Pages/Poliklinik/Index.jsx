import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

function PoliklinikRow({ poliklinik }) {
    const [editing, setEditing] = useState(false);
    const [busy, setBusy] = useState(false);
    const { data, setData, put, processing, reset, errors, clearErrors } = useForm({
        nama_poliklinik: poliklinik.nama_poliklinik,
        is_active: poliklinik.is_active,
    });

    function submitEdit(e) {
        e.preventDefault();
        put(route('poliklinik.update', poliklinik.kode_poliklinik), {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    }

    function cancelEdit() {
        reset();
        clearErrors();
        setEditing(false);
    }

    function toggleActive() {
        setBusy(true);
        router.put(
            route('poliklinik.update', poliklinik.kode_poliklinik),
            { nama_poliklinik: poliklinik.nama_poliklinik, is_active: !poliklinik.is_active },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    }

    return (
        <tr className="hover:bg-gray-50/80 dark:hover:bg-gray-800/40">
            <td className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{poliklinik.kode_poliklinik}</td>
            <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                {editing ? (
                    <form onSubmit={submitEdit} className="flex items-center gap-2">
                        <TextInput
                            value={data.nama_poliklinik}
                            onChange={(e) => setData('nama_poliklinik', e.target.value)}
                            className="w-56"
                        />
                        <PrimaryButton type="submit" disabled={processing}>
                            Simpan
                        </PrimaryButton>
                        <SecondaryButton type="button" onClick={cancelEdit} disabled={processing}>
                            Batal
                        </SecondaryButton>
                        {errors.nama_poliklinik && <InputError message={errors.nama_poliklinik} />}
                    </form>
                ) : (
                    poliklinik.nama_poliklinik
                )}
            </td>
            <td className="px-4 py-3 text-sm">
                <span
                    className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ${
                        poliklinik.is_active
                            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                            : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'
                    }`}
                >
                    {poliklinik.is_active ? 'Aktif' : 'Nonaktif'}
                </span>
            </td>
            <td className="px-4 py-3 text-sm">
                {! editing && (
                    <div className="flex flex-wrap gap-2">
                        <SecondaryButton onClick={() => setEditing(true)}>Ubah Nama</SecondaryButton>
                        <SecondaryButton onClick={toggleActive} disabled={busy}>
                            {poliklinik.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                        </SecondaryButton>
                    </div>
                )}
            </td>
        </tr>
    );
}

export default function PoliklinikIndex({ poliklinik }) {
    const { data, setData, post, processing, reset, errors } = useForm({
        kode_poliklinik: '',
        nama_poliklinik: '',
    });

    function submitPoliklinik(e) {
        e.preventDefault();
        post(route('poliklinik.store'), { preserveScroll: true, onSuccess: () => reset() });
    }

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Poliklinik
                </h2>
            }
        >
            <Head title="Poliklinik" />

            <div className="py-12">
                <div className="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                    <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                        <h3 className="mb-1 text-sm font-semibold text-gray-900 dark:text-gray-100">
                            Tambah Poliklinik
                        </h3>
                        <p className="mb-4 text-xs text-gray-500 dark:text-gray-400">
                            Kode poliklinik harus persis sama dengan kode di SIMRS Khanza (GTK) - dipakai saat
                            mengirim booking/kunjungan ke sana.
                        </p>

                        <form onSubmit={submitPoliklinik} className="flex flex-wrap items-end gap-4">
                            <div>
                                <InputLabel value="Kode Poliklinik" />
                                <TextInput
                                    value={data.kode_poliklinik}
                                    onChange={(e) => setData('kode_poliklinik', e.target.value)}
                                    className="mt-1 w-40"
                                    placeholder="mis. U0002"
                                />
                                <InputError message={errors.kode_poliklinik} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Nama Poliklinik" />
                                <TextInput
                                    value={data.nama_poliklinik}
                                    onChange={(e) => setData('nama_poliklinik', e.target.value)}
                                    className="mt-1 w-64"
                                    placeholder="mis. Tumbuh Kembang Anak"
                                />
                                <InputError message={errors.nama_poliklinik} className="mt-1" />
                            </div>
                            <PrimaryButton type="submit" disabled={processing}>
                                Tambah
                            </PrimaryButton>
                        </form>
                    </div>

                    <div className="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                        <table className="min-w-full divide-y divide-gray-100 dark:divide-gray-800">
                            <thead className="border-b border-gray-200 bg-gray-50/80 dark:border-gray-800 dark:bg-gray-800/40">
                                <tr>
                                    {['Kode', 'Nama Poliklinik', 'Status', ''].map((h) => (
                                        <th
                                            key={h}
                                            className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"
                                        >
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                                {poliklinik.length === 0 && (
                                    <tr>
                                        <td colSpan={4} className="px-4 py-6 text-center text-sm text-gray-500">
                                            Belum ada data poliklinik. Tambahkan lewat form di atas.
                                        </td>
                                    </tr>
                                )}
                                {poliklinik.map((p) => (
                                    <PoliklinikRow key={p.kode_poliklinik} poliklinik={p} />
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
