import { BackLink, Button, Field, FlashMessages, Input, Page, TextArea } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Link, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// Rewrites the text on the public website -- the owner/branch admin picks a page (Home/About/Contact),
// edits whichever blocks they want, and saves. Anything left untouched keeps its original copy
// (App\Support\SiteContentSchema's defaults) -- nothing can ever be "blank" on the live site.
export default function Edit({ page, pageLabels, fields, values }) {
    const { t } = useTranslation();
    const { data, setData, patch, processing, errors } = useForm(values);

    const submit = (e) => {
        e.preventDefault();
        patch(route('site-content.update', page));
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('site_content.title')}</h2>}>
            <Page title={t('site_content.title')} description={t('site_content.desc')} back={<BackLink href={route('school-settings.edit')}>{t('common.back')}</BackLink>}>
                <FlashMessages />

                <div className="flex flex-wrap gap-2" data-testid="site-content-tabs">
                    {Object.entries(pageLabels).map(([key, label]) => (
                        <Link
                            key={key}
                            href={route('site-content.edit', key)}
                            className={`min-h-[44px] rounded-full px-4 py-2 text-sm font-medium ${
                                key === page ? 'bg-brand-600 text-white' : 'bg-stone-100 text-stone-700 hover:bg-stone-200 dark:bg-stone-700 dark:text-stone-300 dark:hover:bg-stone-600'
                            }`}
                        >
                            {label}
                        </Link>
                    ))}
                </div>

                <form onSubmit={submit} className="space-y-5">
                    {Object.entries(fields).map(([key, def]) => (
                        <Field key={key} id={`field_${key}`} label={def.label} error={errors[key]}>
                            {def.type === 'textarea' ? (
                                <TextArea id={`field_${key}`} value={data[key]} onChange={(e) => setData(key, e.target.value)} rows={4} maxLength={4000} />
                            ) : (
                                <Input id={`field_${key}`} value={data[key]} onChange={(e) => setData(key, e.target.value)} maxLength={300} />
                            )}
                        </Field>
                    ))}

                    <Button type="submit" busy={processing}>{t('site_content.save_button')}</Button>
                </form>
            </Page>
        </AuthenticatedLayout>
    );
}
