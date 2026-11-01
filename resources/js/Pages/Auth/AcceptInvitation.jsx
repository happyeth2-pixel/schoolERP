import PasswordField from '@/Components/PasswordField';
import { Button } from '@/Components/ui';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function AcceptInvitation({ valid, token, name, email, school }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({ password: '', password_confirmation: '' });
    const set = (k) => (v) => { clearErrors(k); setData(k, v); };

    if (!valid) {
        return (
            <GuestLayout>
                <Head title={t('auth.invitation_title')} />
                <h2 className="text-lg font-semibold text-stone-900 dark:text-stone-100">{t('auth.invitation_invalid_title')}</h2>
                <p className="mt-2 text-sm text-stone-600 dark:text-stone-400">
                    {t('auth.invitation_invalid_body_1')}
                    {' '}{t('auth.invitation_invalid_body_2')}{' '}
                    <Link href={route('login')} className="text-brand-600 underline dark:text-brand-400">{t('auth.invitation_invalid_login')}</Link>
                    {' '}{t('auth.invitation_invalid_body_3')}
                </p>
            </GuestLayout>
        );
    }

    const submit = (e) => {
        e.preventDefault();
        post(route('invitation.accept', token), { onFinish: () => reset('password', 'password_confirmation') });
    };

    return (
        <GuestLayout>
            <Head title={t('auth.invitation_set_password_title')} />

            <h2 className="text-lg font-semibold text-stone-900 dark:text-stone-100">{t('auth.invitation_welcome', { name })}</h2>
            <p className="mt-1 text-sm text-stone-600 dark:text-stone-400">
                {t('auth.invitation_choose_password', { school: school ? `${school} ` : '' })}<strong>{email}</strong>.
            </p>

            <form onSubmit={submit} className="mt-5 space-y-4">
                <PasswordField id="password" label={t('auth.invitation_new_password_label')} value={data.password} error={errors.password} onChange={set('password')} autoComplete="new-password" autoFocus showToggle={false} />
                <PasswordField id="password_confirmation" label={t('auth.invitation_confirm_password_label')} value={data.password_confirmation} error={errors.password_confirmation} onChange={set('password_confirmation')} autoComplete="new-password" />

                <div className="flex justify-end">
                    <Button type="submit" busy={processing}>{t('auth.invitation_submit_button')}</Button>
                </div>
            </form>
        </GuestLayout>
    );
}
