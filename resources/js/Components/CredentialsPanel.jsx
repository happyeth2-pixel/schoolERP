import { Alert, Button, PrintArea, PrintButton } from '@/Components/ui';
import { CheckIcon, ClipboardDocumentIcon } from '@heroicons/react/24/outline';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

// The account details an administrator hands to someone: who they are, the login (their phone number) and a temporary
// password, shown ONCE. The password is never stored readable and cannot be shown again; a forgotten one is replaced
// with "Reset password". The same panel is used after creating an account and after a password reset.
export default function CredentialsPanel({ credentials, tone = 'panel' }) {
    const { t } = useTranslation();
    const [copied, setCopied] = useState(false);
    const login = credentials.phone ?? credentials.email;
    const role = credentials.role ? t(`staff.role_${credentials.role}`, { defaultValue: credentials.role }) : null;
    const reset = !!credentials.reset;

    const rows = [
        { k: 'name', label: t('cred.name'), value: credentials.name },
        credentials.phone && { k: 'phone', label: t('cred.phone'), value: credentials.phone },
        role && { k: 'role', label: t('cred.role'), value: role },
        credentials.branch && { k: 'branch', label: t('cred.branch'), value: credentials.branch },
        credentials.class && { k: 'class', label: t('cred.class'), value: credentials.class },
        credentials.children?.length > 0 && { k: 'children', label: t('cred.children'), value: credentials.children.join(', ') },
    ].filter(Boolean);

    const copy = async () => {
        const lines = [
            `${t('cred.name')}: ${credentials.name}`,
            ...rows.filter((r) => r.k !== 'name').map((r) => `${r.label}: ${r.value}`),
            `${t('cred.login')}: ${login}`,
            `${t('cred.password')}: ${credentials.password}`,
            '',
            t('cred.give_warning'),
        ];
        try {
            await navigator.clipboard.writeText(lines.join('\n'));
            setCopied(true);
            setTimeout(() => setCopied(false), 2500);
        } catch {
            /* the details are selectable below as a fallback */
        }
    };

    return (
        <div className={`space-y-4 rounded-2xl border-2 p-5 ${tone === 'panel' ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-700 dark:bg-emerald-900/25' : 'border-indigo-300 bg-indigo-50 dark:border-indigo-700 dark:bg-indigo-900/20'}`} data-testid="credentials">
            <PrintArea className="space-y-4 bg-white p-6 text-gray-900 print:w-full">
                <h3 className={`text-xl font-semibold ${reset ? '' : 'hidden print:block'}`} data-testid="cred-title">{reset ? t('cred.reset_title', { name: credentials.name }) : t('cred.created_title')}</h3>
                <dl className="space-y-3 text-base">
                    {rows.map((r) => (
                        <div key={r.k}>
                            <dt className="text-gray-700">{r.label}</dt>
                            <dd className="text-lg font-semibold" data-testid={`cred-${r.k}`}>{r.value}</dd>
                        </div>
                    ))}
                    <div>
                        <dt className="text-gray-700">{t('cred.login')}</dt>
                        <dd className="text-2xl font-bold" data-testid="cred-who">{login}</dd>
                    </div>
                    <div>
                        <dt className="text-gray-700">{t('cred.password')}</dt>
                        <dd className="select-all break-all font-mono text-2xl font-bold tracking-wider" data-testid="cred-password">{credentials.password}</dd>
                    </div>
                </dl>
                <p className="text-base font-medium" data-testid="cred-warning">{t('cred.give_warning')}</p>
            </PrintArea>
            <Alert tone="warning" title={t('cred.once_title')}>{t('cred.note_once')}</Alert>
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start">
                <Button icon={copied ? CheckIcon : ClipboardDocumentIcon} onClick={copy}>{copied ? t('cred.copied') : t('cred.copy')}</Button>
                <PrintButton label={t('cred.print')} />
            </div>
        </div>
    );
}
