import DateInput from '@/Components/DateInput';
import { Button, Card, EmptyState, Input, Page, Select } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ArrowDownTrayIcon } from '@heroicons/react/24/outline';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

// The audit log: who did what, to which record, when, why, and exactly what changed. Every column the old table
// had is still here (time, person, action code, record type and number, before/after, reason, IP address) and
// the filters and the Excel export are unchanged; this only says it in words. The raw action code is always shown
// beside its plain-language sentence, so nothing is hidden and nothing is lost in translation.

const humanise = (s) => String(s).replaceAll('_', ' ').replaceAll('.', ' ');

function useAuditWords() {
    const { t, i18n } = useTranslation();
    const has = (key) => i18n.exists(key);

    const noun = (model) => {
        const key = `audit.noun_${String(model).replace(/([a-z])([A-Z])/g, '$1_$2').toLowerCase()}`;
        return has(key) ? t(key) : humanise(String(model).replace(/([a-z])([A-Z])/g, '$1 $2')).toLowerCase();
    };

    /** "student.promoted" -> "A student was promoted"; unknown codes fall back to "Changed: student", never to nothing. */
    const action = (code) => {
        const named = `audit.action_${code.replaceAll('.', '_')}`;
        if (has(named)) return t(named);
        const [model, ...rest] = code.split('.');
        const verb = rest.join('_');
        const generic = `audit.generic_${verb}`;
        if (has(generic)) return t(generic, { noun: noun(model) });
        return t('audit.generic_other', { noun: noun(model), what: humanise(verb) });
    };

    const value = (v) => {
        if (v === null || v === undefined || v === '') return t('audit.empty');
        if (v === true) return t('audit.yes');
        if (v === false) return t('audit.no');
        return typeof v === 'object' ? JSON.stringify(v) : String(v);
    };

    return { t, action, noun, value };
}

/** field: was X, now Y -- in words as well as colour. */
function Changes({ before, after, limit = 4 }) {
    const { t, value } = useAuditWords();
    if (!before && !after) return null;

    const keys = [...new Set([...Object.keys(before ?? {}), ...Object.keys(after ?? {})])];
    const line = (k) => (
        <li key={k} data-testid="change">
            <span className="font-semibold text-gray-900 dark:text-gray-100">{humanise(k)}</span>
            {': '}
            {before && k in before && after && k in after && <>{t('audit.was')} <span className="rounded bg-red-50 px-1 text-red-800 dark:bg-red-900/30 dark:text-red-200">{value(before[k])}</span>, {t('audit.now')} <span className="rounded bg-green-50 px-1 text-green-800 dark:bg-green-900/30 dark:text-green-200">{value(after[k])}</span></>}
            {before && k in before && !(after && k in after) && <>{t('audit.was')} <span className="rounded bg-red-50 px-1 text-red-800 dark:bg-red-900/30 dark:text-red-200">{value(before[k])}</span></>}
            {!(before && k in before) && after && k in after && <span className="rounded bg-green-50 px-1 text-green-800 dark:bg-green-900/30 dark:text-green-200">{value(after[k])}</span>}
        </li>
    );

    const shown = keys.slice(0, limit);
    const rest = keys.slice(limit);

    return (
        <div>
            <ul className="space-y-1 text-base text-gray-700 dark:text-gray-300">{shown.map(line)}</ul>
            {rest.length > 0 && (
                <details className="mt-2" data-testid="more-changes">
                    <summary className="cursor-pointer text-base font-medium text-indigo-700 dark:text-indigo-300">{t('audit.show_more', { count: rest.length })}</summary>
                    <ul className="mt-2 space-y-1 text-base text-gray-700 dark:text-gray-300">{rest.map(line)}</ul>
                </details>
            )}
        </div>
    );
}

export default function Index({ logs, filters, entities, actions, actors }) {
    const { t, action, noun } = useAuditWords();
    const [q, setQ] = useState(filters.q ?? '');

    const apply = (next) => {
        const merged = { ...filters, ...next };
        Object.keys(merged).forEach((k) => (merged[k] === '' || merged[k] == null) && delete merged[k]);
        router.get(route('audit-log.index'), merged, { preserveState: true, replace: true });
    };

    const exportUrl = route('audit-log.export', filters);
    const hasFilters = Object.values(filters).some(Boolean);

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('audit.page_title')}</h2>}>
            <Page
                title={t('audit.page_title')}
                description={t('audit.page_desc')}
                width="full"
                actions={<><Button as={Link} href={route('security-log.index')} variant="secondary">{t('audit.security_link')}</Button><Button as="a" href={exportUrl} variant="secondary" icon={ArrowDownTrayIcon}>{t('audit.export')}</Button></>}
            >
                <Card data-testid="filters">
                    <h2 className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">{t('audit.filter_title')}</h2>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <form onSubmit={(e) => { e.preventDefault(); apply({ q }); }} className="sm:col-span-2 lg:col-span-3">
                            <label htmlFor="audit-search" className="mb-1 block text-base font-medium text-gray-900 dark:text-gray-100">{t('audit.f_search')}</label>
                            <div className="flex flex-col gap-2 sm:flex-row">
                                <Input id="audit-search" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('audit.f_search_hint')} autoComplete="off" />
                                <Button type="submit" variant="secondary" className="sm:min-w-[140px]">{t('audit.search')}</Button>
                            </div>
                        </form>
                        <div>
                            <label htmlFor="audit-entity" className="mb-1 block text-base font-medium text-gray-900 dark:text-gray-100">{t('audit.f_record')}</label>
                            <Select id="audit-entity" value={filters.entity ?? ''} onChange={(e) => apply({ entity: e.target.value })}>
                                <option value="">{t('audit.all_records')}</option>
                                {entities.map((e) => <option key={e} value={e}>{noun(e).replace(/^./, (c) => c.toUpperCase())}</option>)}
                            </Select>
                        </div>
                        <div>
                            <label htmlFor="audit-action" className="mb-1 block text-base font-medium text-gray-900 dark:text-gray-100">{t('audit.f_action')}</label>
                            <Select id="audit-action" value={filters.action ?? ''} onChange={(e) => apply({ action: e.target.value })}>
                                <option value="">{t('audit.all_actions')}</option>
                                {actions.map((a) => <option key={a} value={a}>{action(a)} ({a})</option>)}
                            </Select>
                        </div>
                        <div>
                            <label htmlFor="audit-who" className="mb-1 block text-base font-medium text-gray-900 dark:text-gray-100">{t('audit.f_who')}</label>
                            <Select id="audit-who" value={filters.user_id ?? ''} onChange={(e) => apply({ user_id: e.target.value })}>
                                <option value="">{t('audit.anyone')}</option>
                                {actors.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
                            </Select>
                        </div>
                        <div>
                            <span className="mb-1 block text-base font-medium text-gray-900 dark:text-gray-100">{t('audit.f_from')}</span>
                            <DateInput value={filters.from ?? ''} onChange={(v) => apply({ from: v })} />
                        </div>
                        <div>
                            <span className="mb-1 block text-base font-medium text-gray-900 dark:text-gray-100">{t('audit.f_to')}</span>
                            <DateInput value={filters.to ?? ''} onChange={(v) => apply({ to: v })} />
                        </div>
                    </div>
                    {hasFilters && (
                        <div className="mt-4">
                            <Button variant="quiet" onClick={() => { setQ(''); router.get(route('audit-log.index')); }}>{t('audit.clear')}</Button>
                        </div>
                    )}
                </Card>

                <p className="text-base text-gray-700 dark:text-gray-300" data-testid="count">{logs.total === 0 ? '' : t('audit.count', { count: logs.total })}</p>

                {logs.data.length === 0 ? (
                    <EmptyState title={hasFilters ? t('audit.no_match') : t('audit.none_yet')}>{hasFilters ? t('audit.no_match_hint') : t('audit.none_yet_hint')}</EmptyState>
                ) : (
                    <ul className="space-y-3" data-testid="entries">
                        {logs.data.map((log) => (
                            <li key={log.id} data-testid="entry" className="rounded-2xl border-2 border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                                <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-1">
                                    <p className="text-lg font-bold text-gray-900 dark:text-gray-100" data-testid="entry-action">{action(log.action)}</p>
                                    <p className="text-base text-gray-600 dark:text-gray-400">{log.created_at}</p>
                                </div>
                                <p className="mt-1 text-base text-gray-900 dark:text-gray-100">
                                    <span className="font-medium">{t('audit.by')}</span> {log.actor}
                                    <span className="mx-2 text-gray-400" aria-hidden="true">·</span>
                                    <span className="font-medium">{t('audit.record')}</span> {noun(log.auditable_type).replace(/^./, (c) => c.toUpperCase())} #{log.auditable_id}{log.subject_label ? ` — ${log.subject_label}` : ''}
                                </p>
                                {log.note && (
                                    <p className="mt-3 rounded-xl bg-amber-50 p-3 text-base text-amber-950 dark:bg-amber-900/20 dark:text-amber-100" data-testid="entry-note"><span className="font-semibold">{t('audit.reason')}:</span> {log.note}</p>
                                )}
                                {(log.before || log.after) && (
                                    <div className="mt-3"><Changes before={log.before} after={log.after} /></div>
                                )}
                                <p className="mt-3 text-sm text-gray-600 dark:text-gray-400">
                                    {t('audit.code')}: <code className="rounded bg-gray-100 px-1 dark:bg-gray-700">{log.action}</code>
                                    {log.ip_address && <> · {t('audit.ip')}: {log.ip_address}</>}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}

                {logs.last_page > 1 && (
                    <nav className="flex flex-wrap items-center justify-between gap-3" aria-label={t('audit.pages')}>
                        {logs.prev_page_url ? <Button as={Link} href={logs.prev_page_url} variant="secondary">{t('audit.newer')}</Button> : <span />}
                        <p className="text-base text-gray-700 dark:text-gray-300">{t('audit.page_of', { page: logs.current_page, total: logs.last_page })}</p>
                        {logs.next_page_url ? <Button as={Link} href={logs.next_page_url} variant="secondary">{t('audit.older')}</Button> : <span />}
                    </nav>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
