import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { BackLink } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

function BranchForm({ branch, onDone }) {
    const { t } = useTranslation();
    const isEdit = !!branch;
    const { data, setData, post, patch, processing, errors, reset } = useForm({
        name: branch?.name ?? '',
        code: branch?.code ?? '',
        address: branch?.address ?? '',
        is_main: branch?.is_main ?? false,
    });

    const submit = (e) => {
        e.preventDefault();
        const options = { onSuccess: () => { reset(); onDone(); } };
        if (isEdit) {
            patch(route('branches.update', branch.id), options);
        } else {
            post(route('branches.store'), options);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-4 p-6">
            <h2 className="text-lg font-medium text-stone-900 dark:text-stone-100">
                {isEdit ? t('branches.edit_title') : t('branches.add_title')}
            </h2>
            <div>
                <InputLabel htmlFor="name" value={t('branches.name_label')} />
                <TextInput
                    id="name"
                    className="mt-1 block w-full"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                />
                <InputError message={errors.name} className="mt-1" />
            </div>
            <div>
                <InputLabel htmlFor="code" value={t('branches.code_label')} />
                <TextInput
                    id="code"
                    className="mt-1 block w-full"
                    value={data.code}
                    onChange={(e) => setData('code', e.target.value)}
                />
                <InputError message={errors.code} className="mt-1" />
            </div>
            <div>
                <InputLabel htmlFor="address" value={t('branches.address_label')} />
                <TextInput
                    id="address"
                    className="mt-1 block w-full"
                    value={data.address}
                    onChange={(e) => setData('address', e.target.value)}
                />
            </div>
            <label className="flex items-center gap-2">
                <Checkbox
                    checked={data.is_main}
                    onChange={(e) => setData('is_main', e.target.checked)}
                />
                <span className="text-sm text-stone-600 dark:text-stone-400">{t('branches.main_campus_label')}</span>
            </label>
            <div className="flex justify-end gap-3">
                <SecondaryButton type="button" onClick={onDone}>{t('common.cancel')}</SecondaryButton>
                <PrimaryButton disabled={processing}>{t('common.save')}</PrimaryButton>
            </div>
        </form>
    );
}

export default function Index({ branches }) {
    const { t } = useTranslation();
    const { flash, auth } = usePage().props;
    const [showCreate, setShowCreate] = useState(false);
    const [editing, setEditing] = useState(null);

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">
                    {t('branches.page_title')}
                </h2>
            }
        >
            <Head title={t('branches.page_title')} />

            <div className="py-8">
                <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-4"><BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink></div>
                    {flash?.success && (
                        <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    <div className="mb-4 flex justify-end">
                        <PrimaryButton onClick={() => setShowCreate(true)}>
                            {t('branches.add_button')}
                        </PrimaryButton>
                    </div>

                    <div className="overflow-x-auto rounded-lg bg-white shadow-sm dark:bg-stone-800">
                        <table className="min-w-full divide-y divide-stone-200 dark:divide-stone-700">
                            <thead className="bg-stone-50 dark:bg-stone-900/40">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">{t('branches.col_name')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">{t('branches.col_code')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">{t('branches.col_staff')}</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-stone-500">{t('branches.col_main')}</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-stone-200 dark:divide-stone-700">
                                {branches.map((branch) => (
                                    <tr key={branch.id}>
                                        <td className="px-4 py-3 text-sm text-stone-900 dark:text-stone-100">{branch.name}</td>
                                        <td className="px-4 py-3 text-sm text-stone-500">{branch.code}</td>
                                        <td className="px-4 py-3 text-sm text-stone-500">{branch.users_count}</td>
                                        <td className="px-4 py-3 text-sm text-stone-500">{branch.is_main ? t('common.yes') : ''}</td>
                                        <td className="px-4 py-3 text-right text-sm">
                                            <button
                                                className="text-brand-600 hover:underline dark:text-brand-400"
                                                onClick={() => setEditing(branch)}
                                            >
                                                {t('common.edit')}
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                                {branches.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-4 py-6 text-center text-sm text-stone-400">
                                            {t('branches.none_yet')}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <Modal show={showCreate} onClose={() => setShowCreate(false)}>
                <BranchForm onDone={() => setShowCreate(false)} />
            </Modal>

            <Modal show={!!editing} onClose={() => setEditing(null)}>
                {editing && <BranchForm branch={editing} onDone={() => setEditing(null)} />}
            </Modal>
        </AuthenticatedLayout>
    );
}
