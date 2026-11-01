import PasswordField from '@/Components/PasswordField';
import { Alert, Button } from '@/Components/ui';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// The first thing a person with a temporary password sees. Nothing else in the system opens until this is done.
export default function ChangeTemporaryPassword({ name }) {
    const { t } = useTranslation();
    const { data, setData, put, processing, errors, reset, clearErrors } = useForm({ current_password: '', password: '', password_confirmation: '' });
    const set = (k) => (v) => { clearErrors(k); setData(k, v); };

    const submit = (e) => {
        e.preventDefault();
        put(route('password.required.update'), { onFinish: () => reset('current_password', 'password', 'password_confirmation') });
    };

    return (
        <GuestLayout>
            <Head title={t('pwreq.title')} />

            <h1 className="text-2xl font-bold text-stone-900 dark:text-stone-100" data-testid="welcome">{t('pwreq.welcome', { name })}</h1>
            <p className="mt-2 text-lg text-stone-700 dark:text-stone-300">{t('pwreq.intro')}</p>

            <form onSubmit={submit} className="mt-6 space-y-5" data-testid="change-form">
                <PasswordField id="current_password" label={t('pwreq.current')} hint={t('pwreq.current_hint')} value={data.current_password} error={errors.current_password} onChange={set('current_password')} autoFocus />
                <PasswordField id="password" label={t('pwreq.new')} hint={t('pwreq.new_hint')} value={data.password} error={errors.password} onChange={set('password')} autoComplete="new-password" showToggle={false} />
                <PasswordField id="password_confirmation" label={t('pwreq.confirm')} value={data.password_confirmation} error={errors.password_confirmation} onChange={set('password_confirmation')} autoComplete="new-password" />
                <Alert tone="info">{t('pwreq.tip')}</Alert>
                <Button type="submit" fullWidth busy={processing}>{t('pwreq.button')}</Button>
            </form>

            <div className="mt-4 text-center">
                <Button variant="quiet" size="md" onClick={() => router.post(route('logout'))}>{t('pwreq.logout')}</Button>
            </div>
        </GuestLayout>
    );
}
