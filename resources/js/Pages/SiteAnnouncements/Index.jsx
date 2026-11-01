import { BackLink, Button, Card, EmptyState, Field, FlashMessages, Input, Page } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { SpeakerWaveIcon, TrashIcon } from '@heroicons/react/24/outline';
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// The banner strip shown at the top of every public website page -- a holiday, a closure, an upcoming
// event. Only the newest active, in-date-range one is ever shown (see SiteAnnouncement::scopeCurrentlyShowable).
export default function Index({ announcements }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({ message: '', starts_on: '', ends_on: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('site-announcements.store'), { preserveScroll: true, onSuccess: () => reset() });
    };

    const toggle = (a) => router.post(route('site-announcements.toggle', a.id), {}, { preserveScroll: true });
    const destroy = (a) => {
        if (confirm(t('site_announcements.confirm_delete'))) {
            router.delete(route('site-announcements.destroy', a.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('site_announcements.title')}</h2>}>
            <Page title={t('site_announcements.title')} description={t('site_announcements.desc')} back={<BackLink href={route('school-settings.edit')}>{t('common.back')}</BackLink>}>
                <FlashMessages />

                <Card>
                    <form onSubmit={submit} className="space-y-4" data-testid="announcement-form">
                        <Field id="ann_message" label={t('site_announcements.message_label')} error={errors.message}>
                            <Input id="ann_message" value={data.message} onChange={(e) => setData('message', e.target.value)} maxLength={300} placeholder={t('site_announcements.message_placeholder')} />
                        </Field>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field id="ann_starts" label={t('site_announcements.starts_label')} error={errors.starts_on}>
                                <Input id="ann_starts" type="date" value={data.starts_on} onChange={(e) => setData('starts_on', e.target.value)} />
                            </Field>
                            <Field id="ann_ends" label={t('site_announcements.ends_label')} error={errors.ends_on}>
                                <Input id="ann_ends" type="date" value={data.ends_on} onChange={(e) => setData('ends_on', e.target.value)} />
                            </Field>
                        </div>
                        <Button type="submit" busy={processing} disabled={data.message.trim() === ''}>{t('site_announcements.add_button')}</Button>
                    </form>
                </Card>

                {announcements.length === 0 ? (
                    <Card><EmptyState icon={SpeakerWaveIcon} title={t('site_announcements.empty_title')}>{t('site_announcements.empty_body')}</EmptyState></Card>
                ) : (
                    <div className="space-y-3">
                        {announcements.map((a) => (
                            <Card key={a.id} data-testid="announcement-row">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="text-base font-medium text-stone-900 dark:text-stone-100">{a.message}</p>
                                        <p className="mt-1 text-sm text-stone-500 dark:text-stone-400">
                                            {a.currently_showing ? t('site_announcements.showing_now') : a.is_active ? t('site_announcements.active_not_in_range') : t('site_announcements.turned_off')}
                                            {(a.starts_on || a.ends_on) && (
                                                <> · {a.starts_on ?? t('site_announcements.no_start')} &rarr; {a.ends_on ?? t('site_announcements.no_end')}</>
                                            )}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2">
                                        <Button type="button" variant="secondary" onClick={() => toggle(a)}>
                                            {a.is_active ? t('site_announcements.turn_off') : t('site_announcements.turn_on')}
                                        </Button>
                                        <button type="button" onClick={() => destroy(a)} className="flex h-10 w-10 items-center justify-center rounded-lg text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30" aria-label={t('site_announcements.delete')}>
                                            <TrashIcon className="h-5 w-5" />
                                        </button>
                                    </div>
                                </div>
                            </Card>
                        ))}
                    </div>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
