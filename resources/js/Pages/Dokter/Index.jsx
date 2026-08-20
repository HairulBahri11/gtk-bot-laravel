import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

const inputClass =
    'mt-1 w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-[#2a78d6] focus:ring-[#2a78d6] dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100 dark:focus:border-[#3987e5] dark:focus:ring-[#3987e5]';

function DoctorCard({ doctor, poliklinik }) {
    const [editing, setEditing] = useState(false);
    const [busy, setBusy] = useState(false);
    const { data, setData, put, processing, reset, errors, clearErrors } = useForm({
        nama_dokter: doctor.nama_dokter,
        no_hp: doctor.no_hp ?? '',
        kode_poliklinik: doctor.kode_poliklinik ?? '',
        is_active: doctor.is_active,
    });

    function submitEdit(e) {
        e.preventDefault();
        put(route('dokter.update', doctor.kode_dokter), {
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
            route('dokter.update', doctor.kode_dokter),
            {
                nama_dokter: doctor.nama_dokter,
                no_hp: doctor.no_hp ?? '',
                kode_poliklinik: doctor.kode_poliklinik ?? '',
                is_active: ! doctor.is_active,
            },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    }

    if (editing) {
        return (
            <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
                <form onSubmit={submitEdit} className="space-y-3">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Nama Dokter" className="text-xs" />
                            <TextInput
                                value={data.nama_dokter}
                                onChange={(e) => setData('nama_dokter', e.target.value)}
                                className="mt-1 w-full text-sm"
                            />
                            <InputError message={errors.nama_dokter} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="No. HP (WhatsApp)" className="text-xs" />
                            <TextInput
                                value={data.no_hp}
                                onChange={(e) => setData('no_hp', e.target.value)}
                                className="mt-1 w-full text-sm"
                                placeholder="mis. 08123456789"
                            />
                            <InputError message={errors.no_hp} className="mt-1" />
                        </div>
                        <div className="sm:col-span-2">
                            <InputLabel value="Poliklinik" className="text-xs" />
                            <select
                                value={data.kode_poliklinik}
                                onChange={(e) => setData('kode_poliklinik', e.target.value)}
                                className={inputClass}
                            >
                                <option value="">- Belum ditentukan -</option>
                                {poliklinik.map((p) => (
                                    <option key={p.kode_poliklinik} value={p.kode_poliklinik}>
                                        {p.nama_poliklinik}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.kode_poliklinik} className="mt-1" />
                        </div>
                    </div>
                    <div className="flex gap-2">
                        <PrimaryButton type="submit" disabled={processing}>
                            Simpan
                        </PrimaryButton>
                        <SecondaryButton type="button" onClick={cancelEdit} disabled={processing}>
                            Batal
                        </SecondaryButton>
                    </div>
                </form>
            </div>
        );
    }

    return (
        <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800">
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">
                        {doctor.nama_dokter}
                    </p>
                    <p className="text-xs text-gray-500 dark:text-gray-400">{doctor.kode_dokter}</p>
                </div>
                <span
                    className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-medium ${
                        doctor.is_active
                            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                            : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'
                    }`}
                >
                    {doctor.is_active ? 'Aktif' : 'Nonaktif'}
                </span>
            </div>

            <div className="mt-3 space-y-1 border-t border-gray-100 pt-3 text-sm dark:border-gray-800">
                <p className="text-gray-500 dark:text-gray-400">
                    Poliklinik:{' '}
                    <span className="text-gray-900 dark:text-gray-100">
                        {doctor.nama_poliklinik ?? '-'}
                    </span>
                </p>
                <p className="text-gray-500 dark:text-gray-400">
                    No. HP:{' '}
                    <span className="text-gray-900 dark:text-gray-100">{doctor.no_hp ?? '-'}</span>
                </p>
                {! doctor.no_hp && (
                    <p className="text-xs text-amber-600 dark:text-amber-400">
                        Tanpa No. HP, dokter ini tidak bisa mengelola shift lewat WhatsApp (batal/delay).
                    </p>
                )}
            </div>

            <div className="mt-3 flex gap-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                <SecondaryButton className="flex-1 justify-center" onClick={() => setEditing(true)}>
                    Ubah
                </SecondaryButton>
                <SecondaryButton className="flex-1 justify-center" onClick={toggleActive} disabled={busy}>
                    {doctor.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                </SecondaryButton>
            </div>
        </div>
    );
}

export default function DokterIndex({ doctors, poliklinik }) {
    const { data, setData, post, processing, reset, errors } = useForm({
        kode_dokter: '',
        nama_dokter: '',
        no_hp: '',
        kode_poliklinik: '',
    });

    function submitDoctor(e) {
        e.preventDefault();
        post(route('dokter.store'), { preserveScroll: true, onSuccess: () => reset() });
    }

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Dokter
                </h2>
            }
        >
            <Head title="Dokter" />

            <div className="py-8 sm:py-12">
                <div className="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                    <div className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-200/70 dark:bg-gray-900 dark:ring-gray-800 sm:p-6">
                        <h3 className="mb-1 text-sm font-semibold text-gray-900 dark:text-gray-100">
                            Tambah Dokter
                        </h3>
                        <p className="mb-4 text-xs text-gray-500 dark:text-gray-400">
                            Kode dokter harus persis sama dengan kode di SIMRS Khanza (GTK) - dipakai saat
                            mengirim booking/kunjungan ke sana.
                        </p>

                        <form onSubmit={submitDoctor} className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <InputLabel value="Kode Dokter" />
                                <TextInput
                                    value={data.kode_dokter}
                                    onChange={(e) => setData('kode_dokter', e.target.value)}
                                    className="mt-1 w-full"
                                    placeholder="mis. D0000002"
                                />
                                <InputError message={errors.kode_dokter} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Nama Dokter" />
                                <TextInput
                                    value={data.nama_dokter}
                                    onChange={(e) => setData('nama_dokter', e.target.value)}
                                    className="mt-1 w-full"
                                    placeholder="mis. dr. Retno Wulandari, Sp.A"
                                />
                                <InputError message={errors.nama_dokter} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="No. HP (WhatsApp)" />
                                <TextInput
                                    value={data.no_hp}
                                    onChange={(e) => setData('no_hp', e.target.value)}
                                    className="mt-1 w-full"
                                    placeholder="mis. 08123456789"
                                />
                                <InputError message={errors.no_hp} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Poliklinik" />
                                <select
                                    value={data.kode_poliklinik}
                                    onChange={(e) => setData('kode_poliklinik', e.target.value)}
                                    className={inputClass}
                                >
                                    <option value="">- Belum ditentukan -</option>
                                    {poliklinik.map((p) => (
                                        <option key={p.kode_poliklinik} value={p.kode_poliklinik}>
                                            {p.nama_poliklinik}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.kode_poliklinik} className="mt-1" />
                            </div>
                            <div className="sm:col-span-2">
                                <PrimaryButton type="submit" disabled={processing}>
                                    Tambah
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>

                    <div>
                        <h3 className="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">
                            Daftar Dokter
                        </h3>

                        {doctors.length === 0 ? (
                            <p className="rounded-xl border border-dashed border-gray-300 bg-white p-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
                                Belum ada data dokter. Tambahkan lewat form di atas.
                            </p>
                        ) : (
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {doctors.map((d) => (
                                    <DoctorCard key={d.kode_dokter} doctor={d} poliklinik={poliklinik} />
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
