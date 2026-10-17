import { BackLink, Button, Card, EmptyState, Page, Select } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Link, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// Sign-in security: failed tries, cool-downs, locks, resets and accounts switched off or on. It never shows a
// password or a full phone number (identifiers are stored masked), and only says what happened, not how the
// protection is tuned.

export default function Security({ events, filters, eventTypes }) {
    const { t, i18n } = useTranslation();
    const label = (e) => (i18n.exists(`secevt.e_${e}`) ? t(`secevt.e_${e}`) : e);

    const detail = (e) => {
        const c = e.context ?? {};
        const parts = [];
        if (c.failures) parts.push(t('secevt.d_failures', { count: c.failures }));
        if (c.wait_seconds) parts.push(t('secevt.d_wait', { count: c.wait_seconds }));
        if (c.lock_minutes) parts.push(t('secevt.d_lock', { count: c.lock_minutes }));
        if (c.accounts) parts.push(t('secevt.d_accounts', { count: c.accounts }));
        if (c.known_device) parts.push(t('secevt.d_known'));
        return parts.join(' · ');
    };

    const apply = (event) => router.get(route('security-log.index'), event ? { event } : {}, { preserveState: true, replace: true });

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('secevt.page_title')}</h2>}>
            <Page title={t('secevt.page_title')} description={t('secevt.page_desc')} width="wide" back={<BackLink href={route('audit-log.index')}>{t('secevt.back')}</BackLink>}>
                <Card>
                    <label htmlFor="sec-event" className="mb-1 block text-base font-medium text-gray-900 dark:text-gray-100">{t('secevt.f_event')}</label>
                    <Select id="sec-event" value={filters.event ?? ''} onChange={(e) => apply(e.target.value)}>
                        <option value="">{t('secevt.all')}</option>
                        {eventTypes.map((e) => <option key={e} value={e}>{label(e)}</option>)}
                    </Select>
                </Card>

                {events.data.length === 0 ? (
                    <EmptyState title={t('secevt.none_title')}>{t('secevt.none_body')}</EmptyState>
                ) : (
                    <ul className="space-y-3" data-testid="security-events">
                        {events.data.map((e) => (
                            <li key={e.id} data-testid="security-event" className="rounded-2xl border-2 border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                                <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-1">
                                    <p className="text-lg font-bold text-gray-900 dark:text-gray-100">{label(e.event)}</p>
                                    <p className="text-base text-gray-600 dark:text-gray-400">{e.created_at}</p>
                                </div>
                                <p className="mt-1 text-base text-gray-900 dark:text-gray-100">
                                    {e.who ? <>{t('secevt.account')}: <span className="font-medium">{e.who}</span></> : e.identifier ? <>{t('secevt.number')}: <span className="font-mono">{e.identifier}</span></> : null}
                                    {e.who && e.identifier ? <span className="text-gray-600 dark:text-gray-400"> ({e.identifier})</span> : null}
                                    {e.actor && <> · {t('secevt.by')}: <span className="font-medium">{e.actor}</span></>}
                                </p>
                                {detail(e) && <p className="mt-1 text-base text-gray-700 dark:text-gray-300">{detail(e)}</p>}
                                <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">{e.ip_address ? `${t('secevt.ip')}: ${e.ip_address} · ` : ''}<code className="rounded bg-gray-100 px-1 dark:bg-gray-700">{e.event}</code></p>
                            </li>
                        ))}
                    </ul>
                )}

                {events.last_page > 1 && (
                    <nav className="flex flex-wrap items-center justify-between gap-3">
                        {events.prev_page_url ? <Button as={Link} href={events.prev_page_url} variant="secondary">{t('audit.newer')}</Button> : <span />}
                        <p className="text-base text-gray-700 dark:text-gray-300">{t('audit.page_of', { page: events.current_page, total: events.last_page })}</p>
                        {events.next_page_url ? <Button as={Link} href={events.next_page_url} variant="secondary">{t('audit.older')}</Button> : <span />}
                    </nav>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
