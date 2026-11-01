import AttachmentGrid from '@/Components/AttachmentGrid';
import Avatar from '@/Components/Avatar';
import PrimaryButton from '@/Components/PrimaryButton';
import { BackLink, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

function ManageForm({ complaint }) {
    const { t } = useTranslation();
    const { data, setData, post, processing } = useForm({
        status: complaint.status,
        resolution_note: complaint.resolution_note ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('complaints.status', complaint.id));
    };

    return (
        <form onSubmit={submit} className="space-y-4 rounded-lg bg-white p-6 shadow-sm dark:bg-stone-800">
            <h3 className="text-sm font-semibold uppercase tracking-wide text-stone-400">{t('complaints.manage_title')}</h3>
            <div>
                <label className="block text-sm font-medium text-stone-700 dark:text-stone-300">{t('complaints.status_label')}</label>
                <select
                    className="mt-1 block w-full rounded-md border-stone-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100"
                    value={data.status}
                    onChange={(e) => setData('status', e.target.value)}
                >
                    <option value="open">{t('complaints.filter_open')}</option>
                    <option value="in_progress">{t('complaints.filter_in_progress')}</option>
                    <option value="resolved">{t('complaints.filter_resolved')}</option>
                </select>
            </div>
            <div>
                <label className="block text-sm font-medium text-stone-700 dark:text-stone-300">{t('complaints.resolution_note_label')}</label>
                <textarea
                    rows={4}
                    className="mt-1 block w-full rounded-md border-stone-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100"
                    value={data.resolution_note}
                    onChange={(e) => setData('resolution_note', e.target.value)}
                />
            </div>
            <PrimaryButton disabled={processing}>{t('complaints.save_button')}</PrimaryButton>
        </form>
    );
}

export default function Show({ complaint, canManage }) {
    const { t } = useTranslation();
    const { flash } = usePage().props;
    const title = t('complaints.show_page_title');

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{title}</h2>}>
            <Head title={complaint.subject} />

            <div className="py-8">
                <div className="mx-auto max-w-2xl space-y-4 px-4 sm:px-6 lg:px-8">
                    <BackLink href={route('complaints.index')}>{t('complaints.back_to_list')}</BackLink>

                    {flash?.success && (
                        <div className="rounded-md bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-900/30 dark:text-green-300">
                            {flash.success}
                        </div>
                    )}

                    <div className="rounded-lg bg-white p-6 shadow-sm dark:bg-stone-800">
                        <div className="mb-2 flex items-center justify-between">
                            <h3 className="text-lg font-semibold text-stone-900 dark:text-stone-100">{complaint.subject}</h3>
                            <StatusBadge status={complaint.status} />
                        </div>
                        <p className="text-sm text-stone-500 dark:text-stone-400">{t('complaints.submitted_x', { date: complaint.created_at })}</p>

                        <div className="mt-4 grid gap-3 rounded-md border border-stone-200 p-4 text-sm dark:border-stone-700 sm:grid-cols-2">
                            {canManage && (
                                <div>
                                    <p className="text-xs font-semibold uppercase tracking-wide text-stone-400">{t('complaints.from_label')}</p>
                                    <div className="mt-1 flex items-center gap-2">
                                        <Avatar src={complaint.submitted_by_photo_url} name={complaint.submitted_by} size={28} fallback="initials" />
                                        <p className="font-medium text-stone-900 dark:text-stone-100">{complaint.submitted_by}</p>
                                    </div>
                                    {complaint.submitted_by_email && <p className="text-stone-500">{complaint.submitted_by_email}</p>}
                                    {complaint.submitted_by_phone && <p className="text-stone-500">{complaint.submitted_by_phone}</p>}
                                    {complaint.contact_restricted && <p className="text-xs italic text-stone-400">{t('complaints.contact_restricted_note')}</p>}
                                </div>
                            )}
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-stone-400">{t('complaints.about_label')}</p>
                                {complaint.student?.restricted ? (
                                    <p className="italic text-stone-400">{t('complaints.student_restricted_note')}</p>
                                ) : complaint.student ? (
                                    <p className="font-medium text-stone-900 dark:text-stone-100">{complaint.student.name} <span className="font-normal text-stone-500">— {complaint.student.section}</span></p>
                                ) : (
                                    <p className="text-stone-500">{t('complaints.general_note')}</p>
                                )}
                                {canManage && complaint.siblings?.length > 0 && (
                                    <p className="mt-1 text-stone-500">
                                        {t('complaints.also_has_x', { list: complaint.siblings.map((s) => `${s.name} (${s.section})`).join(', ') })}
                                    </p>
                                )}
                            </div>
                        </div>

                        <p className="mt-4 whitespace-pre-wrap text-sm text-stone-700 dark:text-stone-300">{complaint.body}</p>
                        <AttachmentGrid images={complaint.images} />

                        {complaint.resolution_note && (
                            <div className="mt-4 rounded-md bg-stone-50 p-4 text-sm dark:bg-stone-900/40">
                                <p className="font-medium text-stone-700 dark:text-stone-300">
                                    {t('complaints.response_from_x', { from: complaint.resolved_by ? t('complaints.response_from_name', { name: complaint.resolved_by }) : '' })}
                                </p>
                                <p className="mt-1 whitespace-pre-wrap text-stone-600 dark:text-stone-400">{complaint.resolution_note}</p>
                            </div>
                        )}
                    </div>

                    {canManage && <ManageForm complaint={complaint} />}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
