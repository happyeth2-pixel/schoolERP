import StudentPhotoField from '@/Components/StudentPhotoField';
import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

export default function UpdatePhotoForm({ className = '' }) {
    const { t } = useTranslation();
    const user = usePage().props.auth.user;
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);

    const upload = (file) => {
        setError(null);
        router.post(route('profile.photo'), { photo: file }, {
            forceFormData: true,
            preserveScroll: true,
            onStart: () => setBusy(true),
            onError: (errs) => setError(errs.photo ?? t('photo.err_unreadable')),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-medium text-stone-900 dark:text-stone-100">{t('profile.photo_title')}</h2>
                <p className="mt-1 text-sm text-stone-600 dark:text-stone-400">
                    {t('profile.photo_desc')}
                </p>
            </header>

            <div className="mt-4">
                <StudentPhotoField currentUrl={user.profile_photo_url} onUse={upload} error={error} busy={busy} />
            </div>
        </section>
    );
}
