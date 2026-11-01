import Modal from '@/Components/Modal';
import DateInput from '@/Components/DateInput';
import { BackLink, Button, Card, Field, FlashMessages, Input, Page, StatusBadge, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PlusIcon } from '@heroicons/react/24/outline';
import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

function OpenPeriodDialog({ open, onClose }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({ name: '', start_date: '', end_date: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('accounting-periods.store'), { preserveScroll: true, onSuccess: () => { reset(); onClose(); } });
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg">
            {open && (
                <form onSubmit={submit} className="space-y-6 p-6" data-testid="period-form">
                    <h2 className="text-2xl font-bold text-stone-900 dark:text-stone-100">{t('period.add_title')}</h2>
                    <Field id="period_name" label={t('period.name')} error={errors.name}>
                        <Input value={data.name} onChange={(e) => setData('name', e.target.value)} maxLength={255} autoComplete="off" />
                    </Field>
                    <Field id="period_start" label={t('period.start_date')} error={errors.start_date}>
                        <DateInput value={data.start_date} onChange={(v) => setData('start_date', v)} />
                    </Field>
                    <Field id="period_end" label={t('period.end_date')} error={errors.end_date}>
                        <DateInput value={data.end_date} onChange={(v) => setData('end_date', v)} />
                    </Field>
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <Button type="button" variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
                        <Button type="submit" busy={processing} disabled={!data.name || !data.start_date || !data.end_date}>{t('period.save_button')}</Button>
                    </div>
                </form>
            )}
        </Modal>
    );
}

export default function Index({ periods, canManage, canReopen }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const isTeacher = auth.role === 'teacher';
    const [opening, setOpening] = useState(false);
    const [ask, confirmDialog] = useConfirm();

    const close = async (period) => {
        const confirmed = await ask({
            title: t('period.close_title', { name: period.name }),
            body: t('period.close_body'),
            confirmLabel: t('period.close_button'),
            tone: 'info',
        });
        if (confirmed) router.post(route('accounting-periods.close', period.id), {}, { preserveScroll: true });
    };

    const reopen = async (period) => {
        const confirmed = await ask({
            title: t('period.reopen_title', { name: period.name }),
            body: t('period.reopen_body'),
            confirmLabel: t('period.reopen_button'),
        });
        if (confirmed) router.post(route('accounting-periods.reopen', period.id), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('period.list_title')}</h2>}>
            <Page
                title={t('period.list_title')}
                description={t('period.list_desc')}
                back={<BackLink big={isTeacher} href={route('dashboard')}>{t('common.back')}</BackLink>}
                actions={canManage && <Button icon={PlusIcon} onClick={() => setOpening(true)}>{t('period.add_button')}</Button>}
            >
                <FlashMessages />
                <Card>
                    <ul className="divide-y divide-stone-200 dark:divide-stone-700" data-testid="period-list">
                        {periods.map((p) => (
                            <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 py-3" data-testid="period-row">
                                <div>
                                    <span className="text-base font-semibold text-stone-900 dark:text-stone-100">{p.name}</span>
                                    <span className="ml-2 text-sm text-stone-600 dark:text-stone-400">{p.start_date} &ndash; {p.end_date} &middot; {t('period.entry_count', { count: p.entry_count })}</span>
                                </div>
                                <div className="flex items-center gap-3">
                                    <StatusBadge tone={p.status === 'open' ? 'success' : 'neutral'} label={t(`period.status_${p.status}`)} />
                                    {canManage && p.status === 'open' && <Button size="sm" variant="danger" onClick={() => close(p)}>{t('period.close_button')}</Button>}
                                    {canReopen && p.status === 'closed' && <Button size="sm" variant="quiet" onClick={() => reopen(p)}>{t('period.reopen_button')}</Button>}
                                </div>
                            </li>
                        ))}
                    </ul>
                </Card>
            </Page>
            <OpenPeriodDialog open={opening} onClose={() => setOpening(false)} />
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
