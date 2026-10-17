import { Button, EmptyState } from '@/Components/ui';
import GuestLayout from '@/Layouts/GuestLayout';
import {
    ArrowPathIcon,
    ClockIcon,
    ExclamationTriangleIcon,
    LockClosedIcon,
    MagnifyingGlassIcon,
    WrenchScrewdriverIcon,
} from '@heroicons/react/24/outline';
import { Head, Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// One friendly, plain-language page for every server-side error this app can produce (403/404/419/
// 429/500/503) -- replacing Laravel's own bare default error view, which this low-literacy-first
// product never showed anyone before this fix. Status-specific icon/title/body; one obvious action.

const META = {
    403: { icon: LockClosedIcon, key: 'forbidden' },
    404: { icon: MagnifyingGlassIcon, key: 'not_found' },
    419: { icon: ClockIcon, key: 'expired' },
    429: { icon: ExclamationTriangleIcon, key: 'too_many' },
    500: { icon: WrenchScrewdriverIcon, key: 'server' },
    503: { icon: WrenchScrewdriverIcon, key: 'unavailable' },
};

export default function Error({ status }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const meta = META[status] ?? META[500];
    const title = t(`errors.${meta.key}_title`);
    const body = t(`errors.${meta.key}_body`);

    const action = status === 419
        ? <Button as={Link} href={route('login')} icon={ArrowPathIcon}>{t('errors.sign_in_again')}</Button>
        : auth?.user
            ? <Button as={Link} href={route('dashboard')}>{t('errors.go_to_dashboard')}</Button>
            : <Button as={Link} href={route('login')}>{t('errors.go_to_login')}</Button>;

    return (
        <GuestLayout>
            <Head title={title} />
            <EmptyState icon={meta.icon} title={title} action={action}>
                {body}
            </EmptyState>
        </GuestLayout>
    );
}
