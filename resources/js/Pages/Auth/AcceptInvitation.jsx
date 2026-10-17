import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

export default function AcceptInvitation({ valid, token, name, email, school }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({ password: '', password_confirmation: '' });

    if (!valid) {
        return (
            <GuestLayout>
                <Head title={t('auth.invitation_title')} />
                <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-100">{t('auth.invitation_invalid_title')}</h2>
                <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    {t('auth.invitation_invalid_body_1')}
                    {' '}{t('auth.invitation_invalid_body_2')}{' '}
                    <Link href={route('login')} className="text-indigo-600 underline dark:text-indigo-400">{t('auth.invitation_invalid_login')}</Link>
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

            <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-100">{t('auth.invitation_welcome', { name })}</h2>
            <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {t('auth.invitation_choose_password', { school: school ? `${school} ` : '' })}<strong>{email}</strong>.
            </p>

            <form onSubmit={submit} className="mt-5">
                <div>
                    <InputLabel htmlFor="password" value={t('auth.invitation_new_password_label')} />
                    <TextInput id="password" type="password" className="mt-1 block w-full" value={data.password} autoComplete="new-password" isFocused onChange={(e) => setData('password', e.target.value)} />
                    <InputError message={errors.password} className="mt-2" />
                </div>

                <div className="mt-4">
                    <InputLabel htmlFor="password_confirmation" value={t('auth.invitation_confirm_password_label')} />
                    <TextInput id="password_confirmation" type="password" className="mt-1 block w-full" value={data.password_confirmation} autoComplete="new-password" onChange={(e) => setData('password_confirmation', e.target.value)} />
                </div>

                <div className="mt-6 flex justify-end">
                    <PrimaryButton disabled={processing}>{t('auth.invitation_submit_button')}</PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
