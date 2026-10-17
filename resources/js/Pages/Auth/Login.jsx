import Checkbox from '@/Components/Checkbox';
import PasswordField from '@/Components/PasswordField';
import { Button, Field, Input } from '@/Components/ui';
import i18n from '@/i18n';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

const LANGUAGES = [
    ['en', 'English'],
    ['am', 'አማርኛ'],
    ['om', 'Afaan Oromoo'],
    ['ti', 'ትግርኛ'],
];

function PreLoginLanguagePicker() {
    const [current, setCurrent] = useState(i18n.language);

    const choose = (code) => {
        i18n.changeLanguage(code);
        setCurrent(code);
    };

    return (
        <div className="mb-4 flex flex-wrap justify-center gap-2">
            {LANGUAGES.map(([code, label]) => (
                <button
                    key={code}
                    type="button"
                    onClick={() => choose(code)}
                    aria-pressed={current === code}
                    className={`min-h-[44px] rounded-full px-4 text-sm font-medium ${
                        current === code
                            ? 'bg-indigo-600 text-white'
                            : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600'
                    }`}
                >
                    {label}
                </button>
            ))}
        </div>
    );
}

// Sign in with a phone number (any everyday form: 0911..., 0711..., +251911...). The server turns it into one
// canonical number and gives the same answer for a wrong number and a wrong password, so nothing reveals which
// accounts exist. Forgetting the password is solved at the school office, not by email.
export default function Login({ status }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        phone: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout>
            <Head title={t('auth.log_in')} />

            <PreLoginLanguagePicker />

            <h1 className="mb-5 text-center text-2xl font-bold text-gray-900 dark:text-gray-100">{t('signin.title')}</h1>

            {status && <div className="mb-4 text-base font-medium text-green-700">{status}</div>}

            <form onSubmit={submit} className="space-y-5" data-testid="login-form">
                <Field id="phone" label={t('signin.phone')} hint={t('signin.phone_hint')} error={errors.phone}>
                    <Input
                        type="tel"
                        inputMode="tel"
                        name="phone"
                        value={data.phone}
                        autoComplete="username"
                        autoFocus
                        onChange={(e) => { clearErrors('phone'); setData('phone', e.target.value); }}
                    />
                </Field>

                <PasswordField id="password" label={t('auth.password')} value={data.password} error={errors.password} onChange={(v) => setData('password', v)} />

                <label className="flex min-h-[48px] items-center gap-3">
                    <Checkbox name="remember" checked={data.remember} onChange={(e) => setData('remember', e.target.checked)} />
                    <span className="text-base text-gray-700 dark:text-gray-300">{t('auth.remember_me')}</span>
                </label>

                <Button type="submit" fullWidth busy={processing}>{t('auth.log_in')}</Button>

                <p className="text-center text-base text-gray-700 dark:text-gray-300" data-testid="forgot-note">{t('signin.forgot_office')}</p>
            </form>
        </GuestLayout>
    );
}
