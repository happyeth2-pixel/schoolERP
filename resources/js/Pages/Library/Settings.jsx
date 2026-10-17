import { Alert, Button, Card, CheckRow, Field, FlashMessages, Input, Page } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function Settings({ canManage, loanDays, finePerDay }) {
    const { t } = useTranslation();
    const { data, setData, put, processing, errors } = useForm({
        library_loan_days: String(loanDays),
        fines_enabled: finePerDay !== null,
        library_fine_per_day: finePerDay !== null ? String(finePerDay) : '',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('library-settings.update'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('library.settings_title')}</h2>}>
            <Page title={t('library.settings_title')} description={t('library.settings_desc')} width="narrow">
                <FlashMessages />
                {!canManage && <Alert tone="info">{t('student.read_only')}</Alert>}

                <form onSubmit={submit}>
                    <Card>
                        <fieldset disabled={!canManage} className="space-y-6">
                            <Field id="loan_days" label={t('library.loan_days_label')} hint={t('library.loan_days_hint')} error={errors.library_loan_days}>
                                <Input inputMode="numeric" value={data.library_loan_days} onChange={(e) => setData('library_loan_days', e.target.value)} className="max-w-[8rem]" />
                            </Field>

                            <CheckRow
                                checked={data.fines_enabled}
                                onChange={(v) => setData('fines_enabled', v)}
                                label={t('library.fines_enabled_label')}
                                hint={t('library.fines_enabled_hint')}
                            />

                            {data.fines_enabled && (
                                <Field id="fine_per_day" label={t('library.fine_per_day_label')} error={errors.library_fine_per_day}>
                                    <Input inputMode="decimal" value={data.library_fine_per_day} onChange={(e) => setData('library_fine_per_day', e.target.value)} className="max-w-[8rem]" />
                                </Field>
                            )}
                        </fieldset>

                        {canManage && (
                            <div className="mt-5 flex justify-end">
                                <Button type="submit" busy={processing}>{t('library.save_settings_button')}</Button>
                            </div>
                        )}
                    </Card>
                </form>
            </Page>
        </AuthenticatedLayout>
    );
}
