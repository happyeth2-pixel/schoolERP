import Pagination from '@/Components/Pagination';
import { BackLink, Card, EmptyState, Page } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ChatBubbleLeftRightIcon } from '@heroicons/react/24/outline';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// The public Contact page's inbox: whatever a visitor typed, newest first. Opening a message marks it read.
export default function Index({ inquiries, inquiriesMeta }) {
    const { t } = useTranslation();
    const title = t('contact_inquiries.title');

    const goToPage = (page) => router.get(route('contact-inquiries.index'), { page }, { preserveState: true, preserveScroll: true, replace: true });

    const open = (inquiry) => {
        if (!inquiry.read) {
            router.post(route('contact-inquiries.read', inquiry.id), {}, { preserveScroll: true, preserveState: true });
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{title}</h2>}>
            <Page title={title} description={t('contact_inquiries.desc')} back={<BackLink href={route('school-settings.edit')}>{t('common.back')}</BackLink>}>
                {inquiries.length === 0 ? (
                    <Card><EmptyState icon={ChatBubbleLeftRightIcon} title={t('contact_inquiries.empty_title')}>{t('contact_inquiries.empty_body')}</EmptyState></Card>
                ) : (
                    <>
                        <div className="space-y-3">
                            {inquiries.map((i) => (
                                <Card key={i.id} as="button" onClick={() => open(i)} className={`w-full cursor-pointer text-left transition ${!i.read ? 'border-l-4 border-l-brand-600' : ''}`} data-testid="inquiry-row">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <p className="flex items-center gap-2 text-base font-semibold text-stone-900 dark:text-stone-100">
                                                {i.name}
                                                {!i.read && <span className="rounded-full bg-brand-600 px-2 py-0.5 text-xs font-medium text-white">{t('contact_inquiries.new_badge')}</span>}
                                            </p>
                                            <p className="mt-0.5 text-sm text-stone-500 dark:text-stone-400">
                                                {t('contact_inquiries.phone_label')}: {i.phone}
                                                {i.email && <> · {t('contact_inquiries.email_label')}: {i.email}</>}
                                            </p>
                                        </div>
                                        <span className="shrink-0 text-sm text-stone-500 dark:text-stone-400">{i.created_at}</span>
                                    </div>
                                    <p className="mt-3 whitespace-pre-wrap text-base text-stone-800 dark:text-stone-200">{i.message}</p>
                                </Card>
                            ))}
                        </div>
                        <Pagination meta={inquiriesMeta} onPageChange={goToPage} />
                    </>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
