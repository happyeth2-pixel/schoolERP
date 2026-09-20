import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';

function Select({ id, value, onChange, options, placeholder }) {
    return (
        <select
            id={id}
            className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
            value={value}
            onChange={onChange}
        >
            <option value="">{placeholder}</option>
            {options.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
        </select>
    );
}

const FREQUENCY_LABEL = {
    annual: 'Annual',
    termly: 'Per Term',
    monthly: 'Monthly',
};

function FeeForm({ branches, grades, academicYears }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        branch_id: branches.length === 1 ? branches[0].id : '',
        grade_id: '',
        academic_year_id: academicYears.find((y) => y.is_current)?.id ?? '',
        name: '',
        amount: '',
        frequency: 'annual',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('fee-structures.store'), { onSuccess: () => reset('name', 'amount') });
    };

    return (
        <form onSubmit={submit} className="grid grid-cols-2 gap-4 rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800 sm:grid-cols-6 sm:items-end">
            {branches.length > 1 && (
                <div>
                    <InputLabel htmlFor="branch_id" value="Branch" />
                    <Select id="branch_id" value={data.branch_id} onChange={(e) => setData('branch_id', e.target.value)} options={branches} placeholder="Select…" />
                </div>
            )}
            <div>
                <InputLabel htmlFor="grade_id" value="Grade" />
                <Select id="grade_id" value={data.grade_id} onChange={(e) => setData('grade_id', e.target.value)} options={grades} placeholder="Select…" />
                <InputError message={errors.grade_id} className="mt-1" />
            </div>
            <div>
                <InputLabel htmlFor="academic_year_id" value="Year" />
                <Select id="academic_year_id" value={data.academic_year_id} onChange={(e) => setData('academic_year_id', e.target.value)} options={academicYears} placeholder="Select…" />
            </div>
            <div>
                <InputLabel htmlFor="name" value="Fee Name" />
                <TextInput id="name" className="mt-1 block w-full" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                <InputError message={errors.name} className="mt-1" />
            </div>
            <div>
                <InputLabel htmlFor="amount" value="Amount (ETB)" />
                <TextInput id="amount" type="number" step="0.01" className="mt-1 block w-full" value={data.amount} onChange={(e) => setData('amount', e.target.value)} />
                <InputError message={errors.amount} className="mt-1" />
            </div>
            <div>
                <InputLabel htmlFor="frequency" value="Billed" />
                <select
                    id="frequency"
                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                    value={data.frequency}
                    onChange={(e) => setData('frequency', e.target.value)}
                >
                    <option value="annual">Annual</option>
                    <option value="termly">Per Term</option>
                    <option value="monthly">Monthly</option>
                </select>
            </div>
            <PrimaryButton disabled={processing}>Add</PrimaryButton>
        </form>
    );
}

export default function Index({ feeStructures, branches, grades, academicYears }) {
    const { flash } = usePage().props;

    const destroy = (fs) => {
        if (confirm(`Remove ${fs.name}?`)) {
            router.delete(route('fee-structures.destroy', fs.id));
        }
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Fee Structures</h2>}
        >
            <Head title="Fee Structures" />

            <div className="py-8">
                <div className="mx-auto max-w-4xl space-y-4 px-4 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    <FeeForm branches={branches} grades={grades} academicYears={academicYears} />

                    <div className="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead className="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Branch</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Grade</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Year</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Fee</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Amount</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Billed</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                                {feeStructures.map((fs) => (
                                    <tr key={fs.id}>
                                        <td className="px-4 py-3 text-sm text-gray-500">{fs.branch.name}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{fs.grade.name}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{fs.academic_year.name}</td>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{fs.name}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{Number(fs.amount).toLocaleString()} ETB</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{FREQUENCY_LABEL[fs.frequency] ?? fs.frequency}</td>
                                        <td className="px-4 py-3 text-right text-sm">
                                            <button className="text-red-600 hover:underline dark:text-red-400" onClick={() => destroy(fs)}>Remove</button>
                                        </td>
                                    </tr>
                                ))}
                                {feeStructures.length === 0 && (
                                    <tr><td colSpan={7} className="px-4 py-6 text-center text-sm text-gray-400">No fee items yet.</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
