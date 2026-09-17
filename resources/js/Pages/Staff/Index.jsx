import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SearchInput from '@/Components/SearchInput';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ArrowDownTrayIcon } from '@heroicons/react/24/outline';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const ROLE_LABELS = {
    owner: 'Owner',
    branch_admin: 'Branch Admin',
    teacher: 'Teacher',
    accountant: 'Accountant',
    auditor: 'Auditor',
};

function PermissionChecklist({ available, myPermissions, selected, onToggle }) {
    return (
        <div className="grid max-h-56 grid-cols-1 gap-1.5 overflow-y-auto rounded-md border border-gray-200 p-3 sm:grid-cols-2 dark:border-gray-700">
            {available.map((perm) => {
                const grantable = myPermissions.includes(perm);
                return (
                    <label
                        key={perm}
                        className={`flex items-center gap-2 text-sm ${grantable ? 'text-gray-700 dark:text-gray-300' : 'cursor-not-allowed text-gray-300 dark:text-gray-600'}`}
                        title={grantable ? '' : "You can't grant a permission you don't have"}
                    >
                        <Checkbox
                            disabled={!grantable}
                            checked={selected.includes(perm)}
                            onChange={() => onToggle(perm)}
                        />
                        {perm}
                    </label>
                );
            })}
        </div>
    );
}

function CreateStaffForm({ assignableRoles, availablePermissions, myPermissions, branches, onDone }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        title: '',
        role: assignableRoles[0] ?? '',
        branch_id: branches.length === 1 ? branches[0].id : '',
        permissions: [],
    });

    const needsBranch = data.role !== 'owner' && data.role !== 'auditor';

    const togglePermission = (perm) => {
        setData('permissions', data.permissions.includes(perm)
            ? data.permissions.filter((p) => p !== perm)
            : [...data.permissions, perm]);
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('staff.store'), { onSuccess: () => { reset(); onDone(); } });
    };

    return (
        <form onSubmit={submit} className="space-y-4 p-6">
            <h2 className="text-lg font-medium text-gray-900 dark:text-gray-100">Add Staff Account</h2>

            <div className="grid grid-cols-2 gap-4">
                <div>
                    <InputLabel htmlFor="name" value="Full Name" />
                    <TextInput id="name" className="mt-1 block w-full" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                    <InputError message={errors.name} className="mt-1" />
                </div>
                <div>
                    <InputLabel htmlFor="email" value="Email" />
                    <TextInput id="email" type="email" className="mt-1 block w-full" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                    <InputError message={errors.email} className="mt-1" />
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div>
                    <InputLabel htmlFor="role" value="Tier" />
                    <select
                        id="role"
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        value={data.role}
                        onChange={(e) => setData('role', e.target.value)}
                    >
                        {assignableRoles.map((r) => (
                            <option key={r} value={r}>{ROLE_LABELS[r]}</option>
                        ))}
                    </select>
                </div>
                <div>
                    <InputLabel htmlFor="title" value="Title (e.g. Vice Principal)" />
                    <TextInput id="title" className="mt-1 block w-full" value={data.title} onChange={(e) => setData('title', e.target.value)} />
                </div>
            </div>

            {needsBranch && branches.length > 1 && (
                <div>
                    <InputLabel htmlFor="branch_id" value="Branch" />
                    <select
                        id="branch_id"
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        value={data.branch_id}
                        onChange={(e) => setData('branch_id', e.target.value)}
                    >
                        <option value="">Select…</option>
                        {branches.map((b) => (
                            <option key={b.id} value={b.id}>{b.name}</option>
                        ))}
                    </select>
                    <InputError message={errors.branch_id} className="mt-1" />
                </div>
            )}

            {data.role === 'branch_admin' && (
                <div>
                    <InputLabel value="Permissions for this account" />
                    <p className="mb-1 text-xs text-gray-400">
                        Greyed-out permissions can't be granted because you don't hold them yourself.
                    </p>
                    <PermissionChecklist
                        available={availablePermissions}
                        myPermissions={myPermissions}
                        selected={data.permissions}
                        onToggle={togglePermission}
                    />
                </div>
            )}

            <div className="flex justify-end gap-3">
                <SecondaryButton type="button" onClick={onDone}>Cancel</SecondaryButton>
                <PrimaryButton disabled={processing}>Create Account</PrimaryButton>
            </div>
        </form>
    );
}

function EditStaffForm({ staff, availablePermissions, myPermissions, onDone }) {
    const { data, setData, patch, processing, errors } = useForm({
        title: staff.title ?? '',
        status: staff.status,
        permissions: staff.permissions,
    });

    const togglePermission = (perm) => {
        setData('permissions', data.permissions.includes(perm)
            ? data.permissions.filter((p) => p !== perm)
            : [...data.permissions, perm]);
    };

    const submit = (e) => {
        e.preventDefault();
        patch(route('staff.update', staff.id), { onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="space-y-4 p-6">
            <h2 className="text-lg font-medium text-gray-900 dark:text-gray-100">
                Edit {staff.name} <span className="text-sm font-normal text-gray-400">({ROLE_LABELS[staff.role]})</span>
            </h2>

            <div className="grid grid-cols-2 gap-4">
                <div>
                    <InputLabel htmlFor="edit_title" value="Title" />
                    <TextInput id="edit_title" className="mt-1 block w-full" value={data.title} onChange={(e) => setData('title', e.target.value)} />
                    <InputError message={errors.title} className="mt-1" />
                </div>
                <div>
                    <InputLabel htmlFor="edit_status" value="Status" />
                    <select
                        id="edit_status"
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        value={data.status}
                        onChange={(e) => setData('status', e.target.value)}
                    >
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>

            {staff.role === 'branch_admin' && (
                <div>
                    <InputLabel value="Permissions" />
                    <p className="mb-1 text-xs text-gray-400">
                        Greyed-out permissions can't be granted because you don't hold them yourself.
                    </p>
                    <PermissionChecklist
                        available={availablePermissions}
                        myPermissions={myPermissions}
                        selected={data.permissions}
                        onToggle={togglePermission}
                    />
                </div>
            )}

            <div className="flex justify-end gap-3">
                <SecondaryButton type="button" onClick={onDone}>Cancel</SecondaryButton>
                <PrimaryButton disabled={processing}>Save</PrimaryButton>
            </div>
        </form>
    );
}

export default function Index({ staff, assignableRoles, availablePermissions, myPermissions, branches, canManage }) {
    const { flash } = usePage().props;
    const [showCreate, setShowCreate] = useState(false);
    const [editing, setEditing] = useState(null);
    const [search, setSearch] = useState('');

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return staff;
        return staff.filter((s) =>
            s.name.toLowerCase().includes(q) ||
            s.email.toLowerCase().includes(q) ||
            (s.title ?? '').toLowerCase().includes(q) ||
            (ROLE_LABELS[s.role] ?? s.role).toLowerCase().includes(q)
        );
    }, [search, staff]);

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">Staff Accounts</h2>}
        >
            <Head title="Staff Accounts" />

            <div className="py-8">
                <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                    {flash?.success && (
                        <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <div className="w-full max-w-xs">
                            <SearchInput value={search} onChange={setSearch} placeholder="Search name, email, title, tier…" />
                        </div>
                        <div className="flex gap-3">
                            <a
                                href={route('staff.export')}
                                className="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                            >
                                <ArrowDownTrayIcon className="h-4 w-4" /> Export to Excel
                            </a>
                            {canManage && (
                                <PrimaryButton onClick={() => setShowCreate(true)}>Add Staff Account</PrimaryButton>
                            )}
                        </div>
                    </div>

                    <div className="overflow-hidden rounded-lg bg-white shadow-sm dark:bg-gray-800">
                        <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead className="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Name</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Title</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Tier</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Branch</th>
                                    <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Status</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                                {filtered.map((s) => (
                                    <tr key={s.id}>
                                        <td className="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                            {s.name}
                                            <div className="text-xs text-gray-400">{s.email}</div>
                                        </td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{s.title ?? '—'}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{ROLE_LABELS[s.role] ?? s.role}</td>
                                        <td className="px-4 py-3 text-sm text-gray-500">{s.branch ?? 'Org-wide'}</td>
                                        <td className="px-4 py-3 text-sm">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${s.status === 'active' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400'}`}>
                                                {s.status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right text-sm">
                                            {canManage && (
                                                <button className="text-indigo-600 hover:underline dark:text-indigo-400" onClick={() => setEditing(s)}>Edit</button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {filtered.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-6 text-center text-sm text-gray-400">
                                            {staff.length === 0 ? 'No staff accounts yet.' : 'No staff match your search.'}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <Modal show={showCreate} onClose={() => setShowCreate(false)} maxWidth="xl">
                <CreateStaffForm
                    assignableRoles={assignableRoles}
                    availablePermissions={availablePermissions}
                    myPermissions={myPermissions}
                    branches={branches}
                    onDone={() => setShowCreate(false)}
                />
            </Modal>

            <Modal show={!!editing} onClose={() => setEditing(null)} maxWidth="xl">
                {editing && (
                    <EditStaffForm
                        staff={editing}
                        availablePermissions={availablePermissions}
                        myPermissions={myPermissions}
                        onDone={() => setEditing(null)}
                    />
                )}
            </Modal>
        </AuthenticatedLayout>
    );
}
