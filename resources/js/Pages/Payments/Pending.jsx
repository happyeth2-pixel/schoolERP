import { BackLink, Button, Card, EmptyState, Field, Page, Select, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BanknotesIcon, BuildingLibraryIcon, CheckCircleIcon, DevicePhoneMobileIcon, QuestionMarkCircleIcon } from '@heroicons/react/24/outline';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

// The queue of payments waiting to be checked, oldest first. Each card answers who / how much / for what / how at a
// glance and has ONE button: open it. The deciding (Payment Received / Reject Payment) happens on the payment's own
// page, where the proof is large. Same data and the same branch scope as before; nothing is decided from this list.

const METHOD_ICON = { bank_transfer: BuildingLibraryIcon, mobile_money: DevicePhoneMobileIcon, cash: BanknotesIcon, other: QuestionMarkCircleIcon };
const money = (n) => Number(n).toLocaleString(undefined, { maximumFractionDigits: 2 });

function PaymentCard({ p, first }) {
    const { t } = useTranslation();
    const Icon = METHOD_ICON[p.method] ?? METHOD_ICON.other;
    const blocked = p.invoice_waived || Number(p.amount) > Number(p.invoice_balance) + 0.001;

    return (
        <Card as="section" data-testid="payment-card" data-payment-id={p.id} aria-label={`${p.student} ${money(p.amount)}`}>
            <div className="flex flex-wrap items-start gap-4">
                <div className="min-w-0 flex-1">
                    <p className="text-xl font-bold text-stone-900 dark:text-stone-100">{p.student}</p>
                    <p className="text-base text-stone-700 dark:text-stone-300">{p.grade ? `${p.grade} · ` : ''}{t('verify.paid_by', { name: p.submitted_by })}</p>
                    <p className="mt-2 text-4xl font-bold text-stone-900 dark:text-stone-100">{money(p.amount)} <span className="text-xl font-semibold text-stone-600 dark:text-stone-400">ETB</span></p>
                    <p className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-base text-stone-800 dark:text-stone-200">
                        <span className="inline-flex items-center gap-1.5 font-semibold"><Icon className="h-5 w-5" aria-hidden="true" />{t(`verify.method_${p.method}`)}</span>
                        <span>{p.reference_number ? `${t('verify.reference')}: ${p.reference_number}` : t('verify.no_reference')}</span>
                    </p>
                    <p className="mt-1 text-base text-stone-700 dark:text-stone-300">{t('verify.for_invoice', { invoice: p.invoice_number })}{p.period_label ? ` · ${p.period_label}` : ''} · {p.submitted_at}</p>
                </div>
                {p.proof_is_image && <img src={p.proof_url} alt={t('verify.proof_alt')} className="h-24 w-24 rounded-xl border-2 border-stone-300 object-cover dark:border-stone-600" />}
            </div>
            {blocked && (
                <p className="mt-3 flex items-center gap-2" data-testid="blocked-badge"><StatusBadge tone="warning" label={t(p.invoice_waived ? 'verify.list_blocked_waived' : 'verify.list_blocked_over')} /></p>
            )}
            <div className="mt-4">
                <Button as={Link} href={p.review_url} variant={first ? 'primary' : 'secondary'} fullWidth icon={CheckCircleIcon}>{t('verify.check_button')}</Button>
            </div>
        </Card>
    );
}

export default function Pending({ payments, grades, filters }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const [gradeId, setGradeId] = useState(filters?.grade_id ?? '');
    const [method, setMethod] = useState(filters?.method ?? '');

    const apply = (next) => {
        const q = { grade_id: next.gradeId ?? gradeId, method: next.method ?? method };
        router.get(route('payments.pending'), Object.fromEntries(Object.entries(q).filter(([, v]) => v)), { preserveState: true, replace: true });
    };
    const filtered = Boolean(gradeId || method);

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('verify.list_title')}</h2>}>
            <Page title={t('verify.list_title')} description={payments.length > 0 ? t('verify.list_desc', { count: payments.length }) : undefined} width="default" back={<BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>}>
                {payments.length > 0 && (
                    <Button as={Link} href={payments[0].review_url} fullWidth icon={CheckCircleIcon} data-testid="start-oldest">{t('verify.start_oldest')}</Button>
                )}

                <Card>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field id="grade_filter" label={t('verify.filter_grade')}>
                            <Select value={gradeId} onChange={(e) => { setGradeId(e.target.value); apply({ gradeId: e.target.value }); }}>
                                <option value="">{t('verify.filter_all_grades')}</option>
                                {grades.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
                            </Select>
                        </Field>
                        <div>
                            <p className="mb-1.5 text-base font-medium text-stone-900 dark:text-stone-100">{t('verify.filter_method')}</p>
                            <div role="radiogroup" aria-label={t('verify.filter_method')} className="flex flex-wrap gap-2">
                                {[['', t('verify.filter_all_methods')], ['bank_transfer', t('verify.method_bank_transfer')], ['mobile_money', t('verify.method_mobile_money')], ['cash', t('verify.method_cash')], ['other', t('verify.method_other')]].map(([value, label]) => (
                                    <button key={value} type="button" role="radio" aria-checked={method === value} onClick={() => { setMethod(value); apply({ method: value }); }} className={`min-h-[48px] rounded-xl border-2 px-3 text-base font-semibold ${method === value ? 'border-brand-600 bg-brand-600 text-white' : 'border-stone-300 bg-white text-stone-900 dark:border-stone-600 dark:bg-stone-800 dark:text-stone-100'}`}>{label}</button>
                                ))}
                            </div>
                        </div>
                    </div>
                </Card>

                <div className="space-y-4">
                    {payments.map((p, i) => <PaymentCard key={p.id} p={p} first={i === 0} />)}
                    {payments.length === 0 && (
                        <Card>
                            <EmptyState icon={CheckCircleIcon} title={filtered ? t('verify.empty_filtered_title') : t('verify.empty_title')}>{filtered ? t('verify.empty_filtered_body') : t('verify.empty_body')}</EmptyState>
                        </Card>
                    )}
                </div>
            </Page>
        </AuthenticatedLayout>
    );
}
