import { BackLink, Button, Card, EmptyState, Page, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BanknotesIcon, ClipboardDocumentCheckIcon, DocumentChartBarIcon, IdentificationIcon, PencilSquareIcon, PrinterIcon, UserCircleIcon, UserIcon } from '@heroicons/react/24/outline';
import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// "Help a parent": what a member of staff opens when a parent asks in person "how much do I owe?", "was my child
// here today?" or "I need the report card". The parent does not need an account, an email, a smartphone or a
// computer. It adds no power: each part below is only sent to someone who already holds the permission that opens
// that information elsewhere, inside their own branch (StudentDeskController says exactly which). A part that
// is not theirs is shown as a plain "ask the ..." note, so the parent is sent to the right desk instead of a dead end.

const money = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function Section({ id, icon: Icon, title, children }) {
    return (
        <Card as="section" aria-labelledby={`${id}-title`} data-testid={id}>
            <h2 id={`${id}-title`} className="mb-4 flex items-center gap-3 text-2xl font-bold text-gray-900 dark:text-gray-100">
                <Icon className="h-8 w-8 text-indigo-600 dark:text-indigo-400" aria-hidden="true" />
                {title}
            </h2>
            {children}
        </Card>
    );
}

function AskElsewhere({ id, text }) {
    return <p className="rounded-xl bg-gray-50 p-4 text-base text-gray-800 dark:bg-gray-900/40 dark:text-gray-200" data-testid={id}>{text}</p>;
}

export default function Desk({ student, can, fees, attendance, reportCard, profileUrl, viewProfileUrl, cardUrl }) {
    const { t } = useTranslation();

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('desk.title')}</h2>}>
            <Page
                title={student.name}
                description={`${student.class ?? t('find.no_class')} · ${t('find.id_number', { id: student.student_id_number })}`}
                width="default"
                back={<BackLink href={can.profile ? route('students.index') : route('dashboard')}>{can.profile ? t('enroll.back_to_students') : t('desk.back_home')}</BackLink>}
            >
                <Card data-testid="student-actions" as="section" aria-labelledby="actions-title">
                    <h2 id="actions-title" className="mb-3 text-lg font-semibold text-gray-900 dark:text-gray-100">{t('desk.actions_title', { name: student.name })}</h2>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {viewProfileUrl && <Button as={Link} href={viewProfileUrl} variant="secondary" icon={profileUrl ? PencilSquareIcon : UserCircleIcon} data-testid="act-profile">{profileUrl ? t('desk.act_profile_edit') : t('desk.act_profile_view')}</Button>}
                        {can.fees && <Button as="a" href="#desk-fees" variant="secondary" icon={BanknotesIcon} data-testid="act-fees">{t('desk.act_fees')}</Button>}
                        {can.attendance && <Button as="a" href="#desk-attendance" variant="secondary" icon={ClipboardDocumentCheckIcon} data-testid="act-attendance">{t('desk.act_attendance')}</Button>}
                        {can.report_card && <Button as="a" href="#desk-report" variant="secondary" icon={DocumentChartBarIcon} data-testid="act-report">{t('desk.act_report')}</Button>}
                        {cardUrl && <Button as={Link} href={cardUrl} variant="secondary" icon={IdentificationIcon} data-testid="act-card">{t('desk.act_card')}</Button>}
                    </div>
                    {profileUrl && <p className="mt-3 text-base text-gray-600 dark:text-gray-400">{t('desk.act_more_hint')}</p>}
                </Card>

                <Card data-testid="parents">
                    <div className="flex items-start gap-3">
                        <UserIcon className="mt-1 h-7 w-7 shrink-0 text-gray-500" aria-hidden="true" />
                        <div className="min-w-0">
                            <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-100">{t('desk.parents')}</h2>
                            {student.guardians.length === 0 ? (
                                <p className="text-base text-gray-700 dark:text-gray-300" data-testid="no-guardian">{t('desk.no_parent')}</p>
                            ) : (
                                <ul className="mt-1 space-y-1 text-base text-gray-800 dark:text-gray-200">
                                    {student.guardians.map((g) => <li key={g.name}>{g.name} ({g.relationship}){g.phone ? ` · ${g.phone}` : ''}</li>)}
                                </ul>
                            )}
                            <p className="mt-2 text-base text-gray-600 dark:text-gray-400">{t('desk.no_account_needed')}</p>
                            {profileUrl && <Button as={Link} href={profileUrl} variant="quiet" size="md" className="mt-2">{t('desk.open_profile')}</Button>}
                        </div>
                    </div>
                </Card>

                <Section id="desk-fees" icon={BanknotesIcon} title={t('desk.fees_title')}>
                    {!can.fees ? (
                        <AskElsewhere id="fees-elsewhere" text={t('desk.fees_elsewhere')} />
                    ) : (
                        <div className="space-y-5">
                            <div className={`rounded-2xl border-2 p-5 ${fees.owed > 0 ? 'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-900/20' : 'border-emerald-300 bg-emerald-50 dark:border-emerald-700 dark:bg-emerald-900/25'}`}>
                                <p className="text-base text-gray-800 dark:text-gray-200">{fees.owed > 0 ? t('desk.owes') : t('desk.owes_nothing')}</p>
                                {fees.owed > 0 && <p className="text-4xl font-bold text-gray-900 dark:text-gray-100" data-testid="owed">{money(fees.owed)} <span className="text-xl font-semibold text-gray-600 dark:text-gray-400">ETB</span></p>}
                            </div>

                            {fees.invoices.length === 0 ? (
                                <EmptyState title={t('desk.no_bills')} />
                            ) : (
                                <ul className="space-y-3" data-testid="bills">
                                    {fees.invoices.map((i) => (
                                        <li key={i.id} data-testid="bill" data-balance={i.balance} className="rounded-2xl border-2 border-gray-200 p-4 dark:border-gray-700">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <p className="text-lg font-semibold text-gray-900 dark:text-gray-100">{i.period} <span className="text-base font-normal text-gray-600 dark:text-gray-400">· {i.number}</span></p>
                                                <StatusBadge status={i.waived ? 'waived' : i.status} />
                                            </div>
                                            <dl className="mt-2 grid grid-cols-3 gap-2 text-base">
                                                <div><dt className="text-sm text-gray-600 dark:text-gray-400">{t('desk.billed')}</dt><dd className="font-semibold">{money(i.total)}</dd></div>
                                                <div><dt className="text-sm text-gray-600 dark:text-gray-400">{t('desk.paid')}</dt><dd className="font-semibold">{money(i.paid)}</dd></div>
                                                <div><dt className="text-sm text-gray-600 dark:text-gray-400">{t('desk.left')}</dt><dd className="font-semibold" data-testid="bill-left">{money(i.balance)}</dd></div>
                                            </dl>
                                            {i.waiting > 0 && <p className="mt-2 text-base font-medium text-amber-800 dark:text-amber-300">{t('desk.proof_waiting', { count: i.waiting })}</p>}
                                            <div className="mt-3 flex flex-col gap-3 sm:flex-row">
                                                {i.record_url && <Button as={Link} href={i.record_url} icon={BanknotesIcon} data-testid="record-payment">{t('desk.record_payment')}</Button>}
                                                <Button as={Link} href={i.url} variant="secondary">{t('desk.open_bill')}</Button>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {fees.invoices.some((i) => i.balance > 0) && !fees.invoices.some((i) => i.record_url) && <AskElsewhere id="record-elsewhere" text={t('desk.record_elsewhere')} />}

                            {fees.recent_payments.length > 0 && (
                                <div data-testid="recent-payments">
                                    <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">{t('desk.recent_payments')}</h3>
                                    <ul className="mt-2 space-y-2">
                                        {fees.recent_payments.map((p) => (
                                            <li key={p.id} className="flex flex-col gap-2 rounded-xl bg-gray-50 p-3 text-base dark:bg-gray-900/40 sm:flex-row sm:items-center sm:justify-between">
                                                <span>{money(p.amount)} ETB · {t(`verify.method_${p.method}`)} · {p.receipt_number}{p.at_school ? ` · ${t('desk.at_school')}` : ''}<span className="block text-sm text-gray-600 dark:text-gray-400">{p.when}</span></span>
                                                <Button as="a" href={p.receipt_url} size="md" variant="secondary" icon={PrinterIcon}>{t('desk.print_receipt')}</Button>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </div>
                    )}
                </Section>

                <Section id="desk-attendance" icon={ClipboardDocumentCheckIcon} title={t('desk.attendance_title')}>
                    {!can.attendance ? (
                        <AskElsewhere id="attendance-elsewhere" text={t('desk.attendance_elsewhere')} />
                    ) : (
                        <div className="space-y-4">
                            <div className="flex flex-wrap items-center gap-3 rounded-2xl bg-gray-50 p-5 dark:bg-gray-900/40" data-testid="today">
                                <span className="text-lg text-gray-800 dark:text-gray-200">{t('desk.today')}</span>
                                {attendance.today.status ? <StatusBadge status={attendance.today.status} /> : <span className="text-lg font-semibold text-gray-900 dark:text-gray-100">{t('desk.not_marked')}</span>}
                            </div>
                            {attendance.totals.marked > 0 ? (
                                <>
                                    <p className="text-base text-gray-800 dark:text-gray-200" data-testid="rate">{t('desk.rate_line', { rate: attendance.rate, days: attendance.totals.marked })}</p>
                                    <ul className="flex flex-wrap gap-2 text-base" data-testid="totals">
                                        {['present', 'late', 'absent', 'excused', 'sick'].map((s) => <li key={s}><StatusBadge status={s} label={`${t(`attendance.status_${s}`)}: ${attendance.totals[s]}`} /></li>)}
                                    </ul>
                                    <ul className="divide-y divide-gray-200 rounded-xl border-2 border-gray-200 dark:divide-gray-700 dark:border-gray-700" data-testid="recent-days">
                                        {attendance.recent.map((r) => (
                                            <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-base">
                                                <span>{r.date}{r.remarks ? ` · ${r.remarks}` : ''}</span>
                                                <StatusBadge status={r.status} />
                                            </li>
                                        ))}
                                    </ul>
                                </>
                            ) : <p className="text-base text-gray-700 dark:text-gray-300">{t('desk.no_attendance')}</p>}
                        </div>
                    )}
                </Section>

                <Section id="desk-report" icon={DocumentChartBarIcon} title={t('desk.report_title')}>
                    {!can.report_card ? (
                        <AskElsewhere id="report-elsewhere" text={t('desk.report_elsewhere')} />
                    ) : (
                        <div className="space-y-3">
                            <p className="text-base text-gray-700 dark:text-gray-300">{t('desk.report_note')}</p>
                            <div className="flex flex-col gap-3 sm:flex-row">
                                <Button as={Link} href={reportCard.view_url} icon={DocumentChartBarIcon} data-testid="report-view">{t('desk.report_view')}</Button>
                                <Button as="a" href={reportCard.pdf_url} variant="secondary" icon={PrinterIcon} data-testid="report-pdf">{t('desk.report_pdf')}</Button>
                            </div>
                            <p className="text-sm text-gray-600 dark:text-gray-400">{t('ui.print_hint')}</p>
                        </div>
                    )}
                </Section>
            </Page>
        </AuthenticatedLayout>
    );
}
