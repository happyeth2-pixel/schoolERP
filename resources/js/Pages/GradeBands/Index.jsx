import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

function BandForm() {
    const { data, setData, post, processing, errors, reset } = useForm({
        min_score: '', max_score: '', letter: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('grade-bands.store'), { onSuccess: () => reset() });
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
            <div>
                <InputLabel htmlFor="min_score" value="Min %" />
                <TextInput id="min_score" type="number" step="0.01" className="mt-1 w-24" value={data.min_score} onChange={(e) => setData('min_score', e.target.value)} />
                <InputError message={errors.min_score} className="mt-1" />
            </div>
            <div>
                <InputLabel htmlFor="max_score" value="Max %" />
                <TextInput id="max_score" type="number" step="0.01" className="mt-1 w-24" value={data.max_score} onChange={(e) => setData('max_score', e.target.value)} />
                <InputError message={errors.max_score} className="mt-1" />
            </div>
            <div>
                <InputLabel htmlFor="letter" value="Letter" />
                <TextInput id="letter" className="mt-1 w-24" value={data.letter} onChange={(e) => setData('letter', e.target.value)} />
                <InputError message={errors.letter} className="mt-1" />
            </div>
            <PrimaryButton disabled={processing}>Add Band</PrimaryButton>
        </form>
    );
}

export default function Index({ bands }) {
    const { t } = useTranslation();
    const { flash } = usePage().props;

    const destroy = (band) => {
        if (confirm(t('confirm.grade_bands_remove', { letter: band.letter }))) {
            router.delete(route('grade-bands.destroy', band.id));
        }
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Grading Scale</h2>}
        >
            <Head title="Grading Scale" />

            <div className="py-8">
                <div className="mx-auto max-w-2xl space-y-4 px-4 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    <BandForm />

                    <div className="overflow-x-auto rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead className="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Range</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Letter</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                                {bands.map((b) => (
                                    <tr key={b.id}>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{b.min_score}% – {b.max_score}%</td>
                                        <td className="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">{b.letter}</td>
                                        <td className="px-4 py-3 text-right text-sm">
                                            <button className="text-red-600 hover:underline dark:text-red-400" onClick={() => destroy(b)}>Remove</button>
                                        </td>
                                    </tr>
                                ))}
                                {bands.length === 0 && (
                                    <tr><td colSpan={3} className="px-4 py-6 text-center text-sm text-gray-400">No grading scale defined yet.</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
