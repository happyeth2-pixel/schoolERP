import { MarkProgress, STATUSES, statusLabelKey } from '@/Components/AttendanceParts';
import DateInput from '@/Components/DateInput';
import DateText from '@/Components/DateText';
import { Alert, BackLink, Button, Card, Disclosure, EmptyState, Page, SuccessPanel, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { CheckIcon, ExclamationTriangleIcon, UsersIcon } from '@heroicons/react/24/outline';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

// The subject teacher's task for ONE class period: see the students -> mark Present/Absent/Late/Excused ->
// save -> a clear confirmation. No `sick` status here and no guardian notification on save -- those stay
// the daily/homeroom record's own job (see AttendanceController); this is a separate, per-subject record.
const PERIOD_STATUSES = STATUSES.filter((s) => s.value !== 'sick');

const fromRoster = (roster) => Object.fromEntries(roster.map((r) => [r.enrollment_id, r.status]));

function StatusButtons({ row, value, onChange }) {
    const { t } = useTranslation();

    return (
        <div role="radiogroup" aria-label={row.student_name} className="mt-3 grid grid-cols-4 gap-1.5">
            {PERIOD_STATUSES.map((s) => {
                const selected = value === s.value;
                return (
                    <button
                        key={s.value}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        onClick={() => onChange(s.value)}
                        className={`relative flex min-h-[64px] flex-col items-center justify-center gap-1 rounded-xl border-2 px-0.5 py-1.5 text-center text-[13px] font-semibold leading-tight transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 ${
                            selected
                                ? s.on
                                : 'border-gray-300 bg-white text-gray-800 hover:border-gray-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:border-gray-400'
                        }`}
                    >
                        <s.icon className="h-6 w-6" aria-hidden="true" />
                        <span className="break-words">{t(statusLabelKey(s.value))}</span>
                        {selected && <CheckIcon className="absolute right-1 top-1 h-3.5 w-3.5" aria-hidden="true" />}
                    </button>
                );
            })}
        </div>
    );
}

export default function Take({ slot, date, today, roster, last_saved_by }) {
    const { t } = useTranslation();
    const { errors } = usePage().props;
    const [ask, confirmDialog] = useConfirm();

    const [marks, setMarks] = useState(() => fromRoster(roster));
    const [view, setView] = useState('all');
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(null);

    useEffect(() => { setMarks(fromRoster(roster)); }, [roster]);

    const total = roster.length;
    const isMarked = (r) => Boolean(marks[r.enrollment_id]);
    const markedCount = roster.filter(isMarked).length;
    const unmarkedCount = total - markedCount;

    const changes = roster.filter((r) => isMarked(r) && marks[r.enrollment_id] !== r.status);

    const counts = {};
    roster.forEach((r) => { if (marks[r.enrollment_id]) counts[marks[r.enrollment_id]] = (counts[marks[r.enrollment_id]] ?? 0) + 1; });

    const shown = view === 'unmarked' && unmarkedCount > 0 ? roster.filter((r) => !isMarked(r)) : roster;
    const statusWord = (v) => t(statusLabelKey(v));
    const otherDay = date !== today;

    const setMark = (row, status) => setMarks((m) => ({ ...m, [row.enrollment_id]: status }));
    const markRestPresent = () => setMarks((m) => Object.fromEntries(roster.map((r) => [r.enrollment_id, m[r.enrollment_id] ?? 'present'])));

    const changeDate = async (next) => {
        if (!next || next === date) return;
        if (changes.length > 0) {
            const ok = await ask({
                title: t('attendance.unsaved_title'),
                body: <p>{t('attendance.unsaved_body', { count: changes.length })}</p>,
                confirmLabel: t('attendance.unsaved_confirm'),
                cancelLabel: t('attendance.unsaved_cancel'),
                tone: 'danger',
            });
            if (!ok) return;
        }
        setSaved(null);
        router.get(route('period-attendance.show', slot.id), { date: next });
    };

    const post = () => {
        setSaving(true);
        router.post(
            route('period-attendance.store', slot.id),
            { date, records: changes.map((r) => ({ enrollment_id: r.enrollment_id, status: marks[r.enrollment_id] })) },
            { preserveScroll: false, onSuccess: () => setSaved({ recorded: markedCount }), onFinish: () => setSaving(false) }
        );
    };

    const requestSave = async () => {
        if (changes.length === 0) return;
        if (unmarkedCount > 0) {
            const ok = await ask({
                title: t('attendance.confirm_title'),
                body: <p>{t('attendance.confirm_unmarked', { count: unmarkedCount })}</p>,
                confirmLabel: t('attendance.confirm_yes'),
                cancelLabel: t('attendance.confirm_back'),
                tone: 'info',
            });
            if (!ok) return;
        }
        post();
    };

    const serverError = errors && Object.keys(errors).length > 0 ? errors.records ?? errors.date ?? Object.values(errors)[0] : null;
    const disabledWhy = total === 0 ? null : markedCount === 0 ? t('attendance.why_none') : changes.length === 0 ? t('attendance.why_nothing_new') : null;
    const title = `${slot.subject} -- ${slot.section}`;
    const backHref = route('period-attendance.index');

    if (saved) {
        return (
            <AuthenticatedLayout header={<h2 className="truncate text-lg font-semibold text-gray-800 dark:text-gray-200">{title}</h2>}>
                <Page title={title} width="narrow">
                    <SuccessPanel
                        title={t('attendance.saved_title')}
                        details={
                            <div className="space-y-4">
                                <MarkProgress marked={markedCount} total={total} counts={counts} short />
                                {unmarkedCount > 0 && (
                                    <Alert
                                        tone="warning"
                                        title={t('attendance.saved_unmarked_title', { count: unmarkedCount })}
                                        action={<Button variant="secondary" onClick={() => { setSaved(null); setView('unmarked'); }}>{t('attendance.mark_the_rest')}</Button>}
                                    >
                                        {t('attendance.saved_unmarked_body')}
                                    </Alert>
                                )}
                            </div>
                        }
                        actions={
                            <>
                                <Button as={Link} href={backHref} data-testid="done">{t('attendance.done')}</Button>
                                <Button variant="secondary" onClick={() => setSaved(null)}>{t('attendance.check_or_change')}</Button>
                            </>
                        }
                    >
                        {t('attendance.saved_recorded', { count: markedCount })} · {slot.period_label} · <DateText value={date} />
                    </SuccessPanel>
                </Page>
            </AuthenticatedLayout>
        );
    }

    return (
        <AuthenticatedLayout header={<h2 className="truncate text-lg font-semibold text-gray-800 dark:text-gray-200">{title}</h2>}>
            <Page title={title} description={t('period_attendance.take_intro', { period: slot.period_label, teacher: slot.teacher })} width="narrow" back={<BackLink href={backHref}>{t('period_attendance.back_to_classes')}</BackLink>}>
                {serverError && <Alert tone="danger" title={t('attendance.error_title')}>{serverError}</Alert>}

                <Card>
                    {otherDay ? (
                        <Alert tone="warning" title={t('attendance.other_day_title')}>{t('attendance.other_day_body')} <strong><DateText value={date} /></strong></Alert>
                    ) : (
                        <p className="text-lg font-semibold text-gray-900 dark:text-gray-100">{t('attendance.today_is')} <DateText value={date} /></p>
                    )}
                    {last_saved_by && <p className="mt-3 text-base text-gray-700 dark:text-gray-300" data-testid="last-saved">{t('attendance.last_saved_by', { name: last_saved_by })}</p>}
                    <div className="mt-4">
                        <Disclosure title={t('attendance.change_date')} description={t('attendance.change_date_desc')}>
                            <DateInput large value={date} onChange={changeDate} />
                        </Disclosure>
                    </div>
                </Card>

                {total === 0 ? (
                    <Card>
                        <EmptyState icon={UsersIcon} title={t('attendance.no_students_enrolled')} action={<Button as={Link} href={backHref} variant="secondary">{t('period_attendance.back_to_classes')}</Button>} />
                    </Card>
                ) : (
                    <>
                        <Card>
                            <MarkProgress marked={markedCount} total={total} counts={counts} short />
                            {unmarkedCount > 0 && (
                                <div className="mt-4 space-y-3">
                                    <Button onClick={markRestPresent} fullWidth icon={CheckIcon}>
                                        {unmarkedCount === total ? t('attendance.mark_everyone_present') : t('attendance.mark_rest_present', { n: unmarkedCount })}
                                    </Button>
                                    <p className="text-sm text-gray-600 dark:text-gray-400">{unmarkedCount === total ? t('attendance.mark_all_hint') : t('attendance.mark_rest_hint')}</p>
                                    {unmarkedCount < total && (
                                        <div role="radiogroup" aria-label={t('attendance.filter')} className="flex gap-2">
                                            {[['all', t('attendance.show_all')], ['unmarked', t('attendance.show_unmarked', { n: unmarkedCount })]].map(([value, label]) => (
                                                <button
                                                    key={value}
                                                    type="button"
                                                    role="radio"
                                                    aria-checked={view === value}
                                                    onClick={() => setView(value)}
                                                    className={`min-h-[48px] flex-1 rounded-xl border-2 px-3 text-base font-semibold ${view === value ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 bg-white text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100'}`}
                                                >
                                                    {label}
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            )}
                        </Card>

                        <ul className="space-y-3">
                            {shown.map((r) => {
                                const value = marks[r.enrollment_id];
                                const changed = Boolean(value && r.status && value !== r.status);
                                const state = !value ? 'unmarked' : changed ? 'changed' : 'marked';
                                return (
                                    <li
                                        key={r.enrollment_id}
                                        data-testid="period-student-row"
                                        data-state={state}
                                        className={`rounded-2xl border-2 p-4 ${
                                            state === 'unmarked'
                                                ? 'border-dashed border-amber-500 bg-amber-50/70 dark:border-amber-600 dark:bg-amber-900/15'
                                                : state === 'changed'
                                                  ? 'border-amber-400 bg-white dark:bg-gray-800'
                                                  : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'
                                        }`}
                                    >
                                        <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                                            <p className="text-lg font-semibold text-gray-900 dark:text-gray-100">{r.student_name}</p>
                                            <p className="text-sm text-gray-600 dark:text-gray-400">{r.student_id_number}</p>
                                        </div>
                                        {state === 'unmarked' && (
                                            <p className="mt-1 flex items-center gap-1.5 text-sm font-semibold text-amber-900 dark:text-amber-300">
                                                <ExclamationTriangleIcon className="h-4 w-4" aria-hidden="true" /> {t('attendance.not_marked_yet')}
                                            </p>
                                        )}
                                        {state === 'changed' && (
                                            <p className="mt-1 text-sm font-semibold text-amber-900 dark:text-amber-300" data-testid="was-note">
                                                {r.marked_by
                                                    ? t('attendance.was_saved_by', { status: statusWord(r.status), name: r.marked_by })
                                                    : t('attendance.was_saved', { status: statusWord(r.status) })}
                                            </p>
                                        )}
                                        <StatusButtons row={r} value={value} onChange={(status) => setMark(r, status)} />
                                    </li>
                                );
                            })}
                        </ul>

                        <div className="sticky bottom-0 z-10 -mx-4 border-t-2 border-gray-200 bg-white/95 px-4 py-3 backdrop-blur dark:border-gray-700 dark:bg-gray-900/95 sm:mx-0 sm:rounded-2xl sm:border-2">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <p className="text-base font-semibold text-gray-900 dark:text-gray-100" data-testid="bar-text">
                                    {t('attendance.progress_short', { marked: markedCount, count: total })}
                                    {changes.length > 0 && <span className="block text-sm font-medium text-gray-600 dark:text-gray-400">{t('attendance.ready_to_save', { n: changes.length })}</span>}
                                </p>
                                <Button onClick={requestSave} disabled={changes.length === 0} busy={saving}>
                                    {t(last_saved_by ? 'attendance.save_changes' : 'attendance.save_button')}
                                </Button>
                            </div>
                            {disabledWhy && <p className="mt-2 text-sm font-medium text-gray-700 dark:text-gray-300" role="status">{disabledWhy}</p>}
                        </div>
                    </>
                )}
            </Page>
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
