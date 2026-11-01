import { MarkProgress, STATUSES, statusLabelKey } from '@/Components/AttendanceParts';
import DateInput from '@/Components/DateInput';
import DateText from '@/Components/DateText';
import { Alert, BackLink, Button, Card, Disclosure, EmptyState, Field, Page, SuccessPanel, TextArea, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { ArrowDownTrayIcon, ChatBubbleLeftRightIcon, CheckIcon, ExclamationTriangleIcon, UsersIcon } from '@heroicons/react/24/outline';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

function MessageClassForm({ sectionId }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({ body: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('sections.messages.broadcast', sectionId), { preserveScroll: true, onSuccess: () => reset('body') });
    };

    return (
        <Disclosure title={t('messages.class_broadcast_title')} description={t('messages.class_broadcast_desc')}>
            <form onSubmit={submit} className="space-y-3">
                <Field id="broadcast_body" label={t('messages.write_label')} error={errors.body}>
                    <TextArea value={data.body} onChange={(e) => setData('body', e.target.value)} rows={3} maxLength={2000} />
                </Field>
                <Button type="submit" busy={processing} disabled={data.body.trim() === ''}>{t('messages.send_to_class_button')}</Button>
            </form>
        </Disclosure>
    );
}

// The teacher's daily task: choose the class (previous page) -> see the students -> mark -> save -> a clear confirmation.
//
// Rules from the application that this screen keeps and explains (none were changed):
//  - attendance is one record per student per day; the five statuses are present, absent, late, excused, sick;
//  - marking a student ABSENT tells their linked parents right away;
//  - changing an absence to something else tells those parents it was CORRECTED;
//  - saving again updates the day's record (there is no separate correction screen);
//  - the server accepts a save for only some of the class -- so nobody is silently "present": a student stays
//    UNMARKED until the teacher marks them, and the page says how many are still unmarked.
// Only marks that are new or changed are sent, so a student whose mark did not change is not re-stamped with
// someone else's name.

const fromRoster = (roster) => Object.fromEntries(roster.map((r) => [r.enrollment_id, r.status]));
const withParents = (rows) => rows.filter((r) => r.parent_count > 0).length;

function StatusButtons({ row, value, onChange }) {
    const { t } = useTranslation();

    return (
        <div role="radiogroup" aria-label={row.student_name} className="mt-3 grid grid-cols-5 gap-1.5">
            {STATUSES.map((s) => {
                const selected = value === s.value;
                return (
                    <button
                        key={s.value}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        onClick={() => onChange(s.value)}
                        className={`relative flex min-h-[64px] flex-col items-center justify-center gap-1 rounded-xl border-2 px-0.5 py-1.5 text-center text-[13px] font-semibold leading-tight transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 ${
                            selected
                                ? s.on
                                : 'border-stone-300 bg-white text-stone-800 hover:border-stone-500 dark:border-stone-600 dark:bg-stone-800 dark:text-stone-100 dark:hover:border-stone-400'
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

export default function Take({ section, date, today, roster, last_saved_by, classCount = 1, singleClass = false, canMessage = false }) {
    const { t } = useTranslation();
    const { errors } = usePage().props;
    const [ask, confirmDialog] = useConfirm();

    const [marks, setMarks] = useState(() => fromRoster(roster));
    const [view, setView] = useState('all');
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(null);

    // a new date (or a save) brings a new roster: start from what the server has
    useEffect(() => { setMarks(fromRoster(roster)); }, [roster]);

    const total = roster.length;
    const isMarked = (r) => Boolean(marks[r.enrollment_id]);
    const markedCount = roster.filter(isMarked).length;
    const unmarkedCount = total - markedCount;

    const changes = roster.filter((r) => isMarked(r) && marks[r.enrollment_id] !== r.status);
    const corrections = changes.filter((r) => r.status);
    const newlyAbsent = changes.filter((r) => marks[r.enrollment_id] === 'absent');
    const absenceCorrected = corrections.filter((r) => r.status === 'absent');

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
        router.get(route('attendance.show', section.id), { date: next });
    };

    const post = () => {
        const snapshot = {
            recorded: markedCount,
            corrections: corrections.length,
            absentNotified: withParents(newlyAbsent),
            correctedNotified: withParents(absenceCorrected),
        };
        setSaving(true);
        router.post(
            route('attendance.store', section.id),
            { date, records: changes.map((r) => ({ enrollment_id: r.enrollment_id, status: marks[r.enrollment_id] })) },
            {
                preserveScroll: false,
                onSuccess: () => setSaved(snapshot),
                onFinish: () => setSaving(false),
            }
        );
    };

    // Say what saving will DO before it does it -- but only when something important is about to happen,
    // so an ordinary "everyone present" save stays a single tap.
    const requestSave = async () => {
        if (changes.length === 0) return;
        const notes = [];

        if (newlyAbsent.length > 0) {
            notes.push(
                <li key="absent">
                    {withParents(newlyAbsent) > 0
                        ? t('attendance.confirm_absent', { n: newlyAbsent.length, p: withParents(newlyAbsent) })
                        : t('attendance.confirm_absent_none', { n: newlyAbsent.length })}
                </li>
            );
        }
        if (corrections.length > 0) {
            notes.push(
                <li key="corr">
                    {t('attendance.confirm_corrections', { n: corrections.length })}
                    <ul className="mt-1 space-y-1 pl-4">
                        {corrections.map((r) => (
                            <li key={r.enrollment_id} className="list-disc font-medium">
                                {r.student_name}: {statusWord(r.status)} → {statusWord(marks[r.enrollment_id])}
                            </li>
                        ))}
                    </ul>
                    {absenceCorrected.length > 0 && withParents(absenceCorrected) > 0 && (
                        <span className="mt-1 block">{t('attendance.confirm_corrected_notice')}</span>
                    )}
                </li>
            );
        }
        if (unmarkedCount > 0) {
            notes.push(<li key="unmarked">{t('attendance.confirm_unmarked', { count: unmarkedCount })}</li>);
        }

        if (notes.length > 0) {
            const ok = await ask({
                title: t('attendance.confirm_title'),
                body: <ul className="list-inside space-y-3">{notes}</ul>,
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
    const title = section.grade ? t('attendance.class_line', { grade: section.grade, section: section.name }) : section.label;
    // a teacher with one class is never shown a list of classes: "back" and "done" go home
    const backHref = singleClass ? route('dashboard') : route('attendance.index');
    const backLabel = singleClass ? t('attendance.back_home') : t('attendance.back_to_classes');
    const doneHref = classCount > 1 ? route('attendance.index') : route('dashboard');

    // ------------------------------------------------------------------ the confirmation screen
    if (saved) {
        return (
            <AuthenticatedLayout header={<h2 className="truncate text-lg font-semibold text-stone-800 dark:text-stone-200">{title}</h2>}>
                <Page title={title} width="narrow">
                    <SuccessPanel
                        title={t('attendance.saved_title')}
                        details={
                            <div className="space-y-4">
                                <MarkProgress marked={markedCount} total={total} counts={counts} short />
                                {(saved.absentNotified > 0 || saved.correctedNotified > 0 || saved.corrections > 0) && (
                                    <ul className="space-y-1 text-base text-emerald-950 dark:text-emerald-50">
                                        {saved.absentNotified > 0 && <li>• {t('attendance.saved_parents_absent', { count: saved.absentNotified })}</li>}
                                        {saved.correctedNotified > 0 && <li>• {t('attendance.saved_parents_corrected', { count: saved.correctedNotified })}</li>}
                                        {saved.corrections > 0 && <li>• {t('attendance.saved_corrections', { count: saved.corrections })}</li>}
                                    </ul>
                                )}
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
                                <Button as={Link} href={doneHref} data-testid="done">{t('attendance.done')}</Button>
                                <Button variant="secondary" onClick={() => setSaved(null)}>{t('attendance.check_or_change')}</Button>
                            </>
                        }
                    >
                        {t('attendance.saved_recorded', { count: markedCount })} · {section.label} · <DateText value={date} />
                    </SuccessPanel>
                </Page>
            </AuthenticatedLayout>
        );
    }

    // ------------------------------------------------------------------ marking
    return (
        <AuthenticatedLayout header={<h2 className="truncate text-lg font-semibold text-stone-800 dark:text-stone-200">{title}</h2>}>
            <Page title={title} description={t('attendance.take_intro')} width="narrow" back={<BackLink href={backHref}>{backLabel}</BackLink>}>
                {serverError && <Alert tone="danger" title={t('attendance.error_title')}>{serverError}</Alert>}

                <Card>
                    {otherDay ? (
                        <Alert tone="warning" title={t('attendance.other_day_title')}>{t('attendance.other_day_body')} <strong><DateText value={date} /></strong></Alert>
                    ) : (
                        <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{t('attendance.today_is')} <DateText value={date} /></p>
                    )}
                    {last_saved_by && <p className="mt-3 text-base text-stone-700 dark:text-stone-300" data-testid="last-saved">{t('attendance.last_saved_by', { name: last_saved_by })}</p>}
                    <div className="mt-4">
                        <Disclosure title={t('attendance.change_date')} description={t('attendance.change_date_desc')}>
                            <DateInput large value={date} onChange={changeDate} />
                        </Disclosure>
                    </div>
                </Card>

                {total === 0 ? (
                    <Card>
                        <EmptyState icon={UsersIcon} title={t('attendance.no_students_enrolled')} action={<Button as={Link} href={backHref} variant="secondary">{backLabel}</Button>} />
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
                                    {unmarkedCount < total && (
                                        <div role="radiogroup" aria-label={t('attendance.filter')} className="flex gap-2">
                                            {[['all', t('attendance.show_all')], ['unmarked', t('attendance.show_unmarked', { n: unmarkedCount })]].map(([value, label]) => (
                                                <button
                                                    key={value}
                                                    type="button"
                                                    role="radio"
                                                    aria-checked={view === value}
                                                    onClick={() => setView(value)}
                                                    className={`min-h-[48px] flex-1 rounded-xl border-2 px-3 text-base font-semibold ${view === value ? 'border-brand-600 bg-brand-600 text-white' : 'border-stone-300 bg-white text-stone-900 dark:border-stone-600 dark:bg-stone-800 dark:text-stone-100'}`}
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
                                        data-testid="student-row"
                                        data-state={state}
                                        className={`rounded-2xl border-2 p-4 ${
                                            state === 'unmarked'
                                                ? 'border-dashed border-amber-500 bg-amber-50/70 dark:border-amber-600 dark:bg-amber-900/15'
                                                : state === 'changed'
                                                  ? 'border-amber-400 bg-white dark:bg-stone-800'
                                                  : 'border-stone-200 bg-white dark:border-stone-700 dark:bg-stone-800'
                                        }`}
                                    >
                                        <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                                            <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{r.student_name}</p>
                                            <div className="flex items-center gap-3">
                                                <p className="text-sm text-stone-600 dark:text-stone-400">{r.student_id_number}</p>
                                                {canMessage && (
                                                    <Link
                                                        href={route('students.messages.index', r.student_id)}
                                                        className="flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline dark:text-brand-400"
                                                        data-testid="message-parent-link"
                                                    >
                                                        <ChatBubbleLeftRightIcon className="h-4 w-4" aria-hidden="true" /> {t('messages.nav_button')}
                                                    </Link>
                                                )}
                                            </div>
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

                        <div className="sticky bottom-0 z-10 -mx-4 border-t-2 border-stone-200 bg-white/95 px-4 py-3 backdrop-blur dark:border-stone-700 dark:bg-stone-900/95 sm:mx-0 sm:rounded-2xl sm:border-2">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <p className="text-base font-semibold text-stone-900 dark:text-stone-100" data-testid="bar-text">
                                    {t('attendance.progress_short', { marked: markedCount, count: total })}
                                    {changes.length > 0 && <span className="block text-sm font-medium text-stone-600 dark:text-stone-400">{t('attendance.ready_to_save', { n: changes.length })}</span>}
                                </p>
                                <Button onClick={requestSave} disabled={changes.length === 0} busy={saving}>
                                    {t(last_saved_by ? 'attendance.save_changes' : 'attendance.save_button')}
                                </Button>
                            </div>
                            {disabledWhy && <p className="mt-2 text-sm font-medium text-stone-700 dark:text-stone-300" role="status">{disabledWhy}</p>}
                        </div>

                        <div>
                            <Button as="a" variant="secondary" size="md" icon={ArrowDownTrayIcon} href={route('attendance.export', section.id) + `?date=${date}`}>
                                {t('attendance.export_excel')}
                            </Button>
                        </div>

                        {canMessage && (
                            <Card>
                                <MessageClassForm sectionId={section.id} />
                            </Card>
                        )}
                    </>
                )}
            </Page>
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
