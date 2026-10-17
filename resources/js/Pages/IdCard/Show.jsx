import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Alert, PrintArea, PrintButton } from '@/Components/ui';
import { UserCircleIcon } from '@heroicons/react/24/outline';
import { Head } from '@inertiajs/react';
import { QRCodeSVG } from 'qrcode.react';
import { useTranslation } from 'react-i18next';

const STATE_TONE = { valid: 'success', expired: 'warning', suspended: 'warning', invalid: 'danger' };

export default function Show({ student, school, verifyUrl, cardValidity }) {
    const { t } = useTranslation();
    const stateLabel = t(`idcard.state_${cardValidity.state}`, cardValidity.state);

    return (
        <AuthenticatedLayout
            header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('idcard.page_title')}</h2>}
        >
            <Head title={t('idcard.title_with_name', { name: student.name })} />

            <div className="py-8">
                <div className="mx-auto max-w-md space-y-4 px-4 sm:px-6 lg:px-8">
                    <Alert tone={STATE_TONE[cardValidity.state] ?? 'neutral'} title={t('idcard.status_title', { state: stateLabel })}>
                        {cardValidity.reason} {t('idcard.status_note')}
                    </Alert>

                    <PrintButton label={t('idcard.print_button')} variant="primary" />

                    <PrintArea className="mx-auto flex w-full max-w-[340px] flex-col gap-3 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <p className="text-center text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{school}</p>

                        <div className="flex items-center gap-4">
                            {student.photo_url ? (
                                <img src={student.photo_url} alt={student.name} className="h-20 w-20 rounded-md object-cover" />
                            ) : (
                                <UserCircleIcon className="h-20 w-20 text-gray-300 dark:text-gray-600" />
                            )}
                            <div>
                                <p className="font-semibold text-gray-900 dark:text-gray-100">{student.name}</p>
                                <p className="text-sm text-gray-500">{student.student_id_number}</p>
                            </div>
                        </div>

                        <div className="flex justify-center border-t border-dashed border-gray-200 pt-3 dark:border-gray-700">
                            <QRCodeSVG value={verifyUrl} size={120} />
                        </div>
                        <p className="text-center text-xs text-gray-400">{t('idcard.scan_to_verify')}</p>
                    </PrintArea>

                    <p className="text-center text-xs text-gray-400">
                        {t('idcard.no_photo_note')}
                    </p>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
