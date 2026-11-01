import AttachmentPicker from '@/Components/AttachmentPicker';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { BackLink } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function Create({ children }) {
    const { t } = useTranslation();
    const title = t('complaints.create_page_title');
    const { data, setData, post, processing, errors } = useForm({
        student_id: '',
        subject: '',
        body: '',
        images: [],
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('complaints.store'), { forceFormData: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{title}</h2>}>
            <Head title={title} />

            <div className="py-8">
                <div className="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
                    <BackLink href={route('complaints.index')}>{t('complaints.back_to_list')}</BackLink>

                    <form onSubmit={submit} className="mt-4 space-y-4 rounded-lg bg-white p-6 shadow-sm dark:bg-stone-800">
                        {children.length > 0 && (
                            <div>
                                <InputLabel htmlFor="student_id" value={t('complaints.about_child_label')} />
                                <select
                                    id="student_id"
                                    className="mt-1 block w-full rounded-md border-stone-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100"
                                    value={data.student_id}
                                    onChange={(e) => setData('student_id', e.target.value)}
                                >
                                    <option value="">{t('complaints.student_general')}</option>
                                    {children.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </select>
                            </div>
                        )}

                        <div>
                            <InputLabel htmlFor="subject" value={t('complaints.subject_label')} />
                            <TextInput id="subject" className="mt-1 block w-full" value={data.subject} onChange={(e) => setData('subject', e.target.value)} />
                            <InputError message={errors.subject} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="body" value={t('complaints.details_label')} />
                            <textarea
                                id="body"
                                rows={6}
                                className="mt-1 block w-full rounded-md border-stone-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-stone-700 dark:bg-stone-900 dark:text-stone-100"
                                value={data.body}
                                onChange={(e) => setData('body', e.target.value)}
                            />
                            <InputError message={errors.body} className="mt-1" />
                        </div>

                        <AttachmentPicker value={data.images} onChange={(images) => setData('images', images)} error={errors.images} busy={processing} />

                        <PrimaryButton disabled={processing}>{t('complaints.submit_button')}</PrimaryButton>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
