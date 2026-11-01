import { BackLink, Button, Card, EmptyState, FlashMessages, Page, Select, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PlusIcon, UserPlusIcon } from '@heroicons/react/24/outline';
import { Link, router, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// Extra services (transport routes, after-school classes). Adding one is the four-step page (OptionalServices/Wizard);
// here each is a card with what it costs, who is in it and the two things that can be done to it. Nothing is charged
// until a student is enrolled. Changing the kind and removing keep their old rules and now say what they do first.

const KINDS = ['transport', 'asp', 'other'];
const money = (n) => Number(n).toLocaleString(undefined, { maximumFractionDigits: 2 });

export default function Index({ optionalServices, branches }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const [ask, confirmDialog] = useConfirm();

    const changeKind = async (s, kind) => {
        const ok = await ask({
            tone: 'primary',
            title: t('svc.kind_title', { name: s.name }),
            body: <><p>{t('svc.kind_body', { name: s.name, kind: t(`svc.kind_${kind}`) })}</p>{kind === 'transport' && <p>{t('svc.kind_transport_note')}</p>}</>,
            confirmLabel: t('svc.kind_button', { kind: t(`svc.kind_${kind}`) }),
        });
        if (ok) router.patch(route('optional-services.update', s.id), { name: s.name, amount: s.amount, frequency: s.frequency, category: kind }, { preserveScroll: true });
    };

    const remove = async (s) => {
        const ok = await ask({
            title: t('svc.remove_title', { name: s.name }),
            body: <><p>{t('svc.remove_body')}</p>{s.enrolled_count > 0 && <p>{t('svc.remove_enrolled', { count: s.enrolled_count })}</p>}</>,
            confirmLabel: t('svc.remove_button', { name: s.name }),
        });
        if (ok) router.delete(route('optional-services.destroy', s.id), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('svc.page_title')}</h2>}>
            <Page
                title={t('svc.page_title')}
                description={t('svc.page_desc')}
                width="wide"
                back={<BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>}
                actions={<Button as={Link} href={route('optional-services.create')} icon={PlusIcon}>{t('svc.add_button')}</Button>}
            >
                <FlashMessages errorKeys={['category']} />

                {optionalServices.length === 0 ? (
                    <EmptyState title={t('svc.none_title')} action={<Button as={Link} href={route('optional-services.create')} icon={PlusIcon}>{t('svc.add_button')}</Button>}>{t('svc.none_body')}</EmptyState>
                ) : (
                    <ul className="grid gap-4 lg:grid-cols-2" data-testid="services">
                        {optionalServices.map((s) => (
                            <li key={s.id}>
                                <Card data-testid="service-card" className="h-full">
                                    <p className="text-xl font-bold text-stone-900 dark:text-stone-100">{s.name}</p>
                                    <p className="text-base text-stone-600 dark:text-stone-400">{branches.length > 1 ? `${s.branch} · ` : ''}{s.academic_year}</p>
                                    <p className="mt-3 text-lg font-semibold text-stone-900 dark:text-stone-100">{money(s.amount)} ETB · {t(`fee.freq_${s.frequency}`)}</p>
                                    <p className="text-base text-stone-700 dark:text-stone-300">{t('svc.enrolled_n', { count: s.enrolled_count })}</p>
                                    {s.category === 'asp' && (
                                        <p className="text-base text-stone-600 dark:text-stone-400">
                                            {s.period ? `${t('svc.period_label')}: ${s.period}` : t('svc.schedule_none')}
                                            {s.teacher ? ` · ${s.teacher}` : ''}
                                            {s.days_of_week.length > 0 ? ` · ${s.days_of_week.map((d) => t(`svc.day_${d}`)).join(', ')}` : ''}
                                        </p>
                                    )}
                                    <div className="mt-4">
                                        <label htmlFor={`kind-${s.id}`} className="mb-1 block text-base font-medium text-stone-900 dark:text-stone-100">{t('svc.kind_field')}</label>
                                        <Select id={`kind-${s.id}`} value={s.category} onChange={(e) => e.target.value !== s.category && changeKind(s, e.target.value)}>
                                            {KINDS.map((k) => <option key={k} value={k}>{t(`svc.kind_${k}`)}</option>)}
                                        </Select>
                                    </div>
                                    <div className="mt-4 flex flex-wrap gap-2">
                                        <Button as={Link} href={route('optional-services.enroll-form', s.id)} size="md" variant="secondary" icon={UserPlusIcon}>{t('svc.enroll_students')}</Button>
                                        <Button size="md" variant="quiet" onClick={() => remove(s)}>{t('svc.remove')}</Button>
                                    </div>
                                </Card>
                            </li>
                        ))}
                    </ul>
                )}
            </Page>
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
