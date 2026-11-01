import IdCardFace from '@/Components/IdCardFace';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Alert, BackLink, PrintArea, PrintButton } from '@/Components/ui';
import { Head } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

const STATE_TONE = { valid: 'success', expired: 'warning', suspended: 'warning', invalid: 'danger' };

export default function Show({ student, school }) {
    const { t } = useTranslation();
    const stateLabel = t(`idcard.state_${student.card_validity.state}`, student.card_validity.state);

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('idcard.page_title')}</h2>}
        >
            <Head title={t('idcard.title_with_name', { name: student.name })} />

            <div className="py-8">
                <div className="mx-auto max-w-md space-y-4 px-4 sm:px-6 lg:px-8">
                    <BackLink href={route('students.edit', student.id)}>{t('idcard.back_to_profile')}</BackLink>

                    <Alert tone={STATE_TONE[student.card_validity.state] ?? 'neutral'} title={t('idcard.status_title', { state: stateLabel })}>
                        {student.card_validity.reason} {t('idcard.status_note')}
                    </Alert>

                    <PrintButton label={t('idcard.print_button')} variant="primary" />

                    <div className="flex justify-center">
                        <PrintArea className="flex w-full max-w-[428px] justify-center">
                            <IdCardFace student={student} school={school} className="w-full" />
                        </PrintArea>
                    </div>

                    <style>{`
                        @media print {
                            .id-card-print {
                                width: var(--card-w, 85.6mm) !important;
                                height: var(--card-h, 54mm) !important;
                                max-width: none !important;
                                aspect-ratio: auto !important;
                                box-shadow: none !important;
                                border-width: 0.3mm !important;
                            }
                        }
                    `}</style>

                    <p className="text-center text-xs text-stone-400">
                        {t('idcard.no_photo_note')}
                    </p>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
