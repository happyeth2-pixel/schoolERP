import { CheckCircleIcon, ClockIcon, NoSymbolIcon, UserCircleIcon, XCircleIcon } from '@heroicons/react/24/outline';
import { Head } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// Public page. Only a VALID card shows anything about the person; every other
// state shows just the state and what to do. The states are calculated on the
// server from the student's status and enrollment.
const STATES = {
    expired: {
        icon: ClockIcon,
        color: 'text-amber-500',
        pill: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
        labelKey: 'idcard.verify_expired_label',
        titleKey: 'idcard.verify_expired_title',
        textKey: 'idcard.verify_expired_text',
    },
    suspended: {
        icon: NoSymbolIcon,
        color: 'text-orange-500',
        pill: 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300',
        labelKey: 'idcard.verify_suspended_label',
        titleKey: 'idcard.verify_suspended_title',
        textKey: 'idcard.verify_suspended_text',
    },
    invalid: {
        icon: XCircleIcon,
        color: 'text-red-500',
        pill: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
        labelKey: 'idcard.verify_invalid_label',
        titleKey: 'idcard.verify_invalid_title',
        textKey: 'idcard.verify_invalid_text',
    },
};

export default function Verify({ state, name, photo_url, school }) {
    const { t } = useTranslation();
    const info = STATES[state] ?? STATES.invalid;
    const Icon = info.icon;

    return (
        <div className="flex min-h-screen items-center justify-center bg-stone-100 px-4 dark:bg-stone-900">
            <Head title={t('idcard.verify_page_title')}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            <div className="w-full max-w-sm rounded-xl bg-white p-6 text-center shadow-md dark:bg-stone-800">
                {state !== 'valid' ? (
                    <>
                        <Icon className={`mx-auto h-16 w-16 ${info.color}`} />
                        <div className={`mx-auto mt-3 flex w-fit items-center rounded-full px-4 py-1 text-sm font-semibold ${info.pill}`}>{t(info.labelKey)}</div>
                        <p className="mt-3 text-lg font-semibold text-stone-900 dark:text-stone-100">{t(info.titleKey)}</p>
                        <p className="mt-1 text-sm text-stone-500">{t(info.textKey)}</p>
                    </>
                ) : (
                    <>
                        {photo_url ? (
                            <img src={photo_url} alt={name} className="mx-auto h-24 w-24 rounded-md object-cover" />
                        ) : (
                            <UserCircleIcon className="mx-auto h-24 w-24 text-stone-300 dark:text-stone-600" />
                        )}
                        <p className="mt-3 text-lg font-semibold text-stone-900 dark:text-stone-100">{name}</p>
                        <p className="text-sm text-stone-500">{school}</p>

                        <div className="mx-auto mt-4 flex w-fit items-center gap-2 rounded-full bg-green-100 px-4 py-1.5 text-sm font-medium text-green-700 dark:bg-green-900/40 dark:text-green-300">
                            <CheckCircleIcon className="h-5 w-5" />
                            {t('idcard.verify_valid_pill')}
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
