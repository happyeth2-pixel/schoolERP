import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

const STATUS_STYLE = {
    unpaid: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    partially_paid: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    paid: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    waived: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400',
};

function GenerateForm({ branches, grades }) {
    const { data, setData, post, processing } = useForm({
        branch_id: branches.length === 1 ? branches[0].id : '',
        grade_id: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('invoices.generate'));
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 shadow-sm dark:bg-gray-800">
            {branches.length > 1 && (
                <select className="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" value={data.branch_id} onChange={(e) => setData('branch_id', e.target.value)}>
                    <option value="">Branch…</option>
                    {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                </select>
            )}
            <select className="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" value={data.grade_id} onChange={(e) => setData('grade_id', e.target.value)}>
                <option value="">Grade…</option>
                {grades.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
            </select>
            <PrimaryButton disabled={processing || !data.grade_id || !data.branch_id}>
                Generate Invoices for Current Year
            </PrimaryButton>
        </form>
    );
}

export default function Index({ invoices, grades, branches, canManage }) {
    const { flash } = usePage().props;

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Invoices</h2>}
        >
            <Head title="Invoices" />

            <div className="py-8">
                <div className="mx-auto max-w-4xl space-y-4 px-4 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    {canManage && <GenerateForm branches={branches} grades={grades} />}

                    <div className="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead className="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Invoice #</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Student</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Total</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Paid</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Balance</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Status</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                                {invoices.map((i) => (
                                    <tr key={i.id}>
                                        <td className="px-4 py-3 text-sm text-gray-500">{i.invoice_number}</td>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{i.student}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{Number(i.total).toLocaleString()}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{Number(i.paid).toLocaleString()}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{Number(i.balance).toLocaleString()}</td>
                                        <td className="px-4 py-3 text-sm">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLE[i.status]}`}>
                                                {i.status.replace('_', ' ')}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right text-sm">
                                            <Link href={route('invoices.show', i.id)} className="text-indigo-600 hover:underline dark:text-indigo-400">View</Link>
                                        </td>
                                    </tr>
                                ))}
                                {invoices.length === 0 && (
                                    <tr><td colSpan={7} className="px-4 py-6 text-center text-sm text-gray-400">No invoices for the current year yet.</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
