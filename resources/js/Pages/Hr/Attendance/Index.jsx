import CorrectionDialog from '@/Components/CorrectionDialog';
import DateInput from '@/Components/DateInput';
import { BackLink, Button, Card, FlashMessages, Input, Page, StatusBadge } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

const STATUS_TONE = { present: 'success', late: 'warning', absent: 'danger', early_departure: 'warning' };

function UnmarkedRow({ row, draft, onChange, t }) {
    return (
        <li className="rounded-2xl border-2 border-dashed border-amber-500 bg-amber-50/70 p-4 dark:border-amber-600 dark:bg-amber-900/15" data-testid="attendance-row" data-state="unmarked">
            <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{row.full_name}</p>
            <div role="radiogroup" aria-label={row.full_name} className="mt-3 grid grid-cols-2 gap-1.5 sm:grid-cols-4">
                {['present', 'late', 'absent', 'early_departure'].map((s) => (
                    <button
                        key={s}
                        type="button"
                        role="radio"
                        aria-checked={draft?.status === s}
                        onClick={() => onChange(row.employee_id, { ...draft, status: s })}
                        className={`min-h-[56px] rounded-xl border-2 px-2 text-sm font-semibold ${draft?.status === s ? 'border-brand-600 bg-brand-600 text-white' : 'border-stone-300 bg-white text-stone-800 dark:border-stone-600 dark:bg-stone-800 dark:text-stone-100'}`}
                    >
                        {t(`staff_attendance.status_${s}`)}
                    </button>
                ))}
            </div>
            {draft?.status && (
                <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <label className="text-sm text-stone-600 dark:text-stone-400">
                        {t('staff_attendance.check_in')}
                        <Input type="time" value={draft.check_in ?? ''} onChange={(e) => onChange(row.employee_id, { ...draft, check_in: e.target.value })} className="mt-1" />
                    </label>
                    <label className="text-sm text-stone-600 dark:text-stone-400">
                        {t('staff_attendance.check_out')}
                        <Input type="time" value={draft.check_out ?? ''} onChange={(e) => onChange(row.employee_id, { ...draft, check_out: e.target.value })} className="mt-1" />
                    </label>
                    <label className="text-sm text-stone-600 dark:text-stone-400">
                        {t('staff_attendance.overtime_minutes')}
                        <Input type="number" min="0" max="1440" value={draft.overtime_minutes ?? ''} onChange={(e) => onChange(row.employee_id, { ...draft, overtime_minutes: e.target.value })} className="mt-1" />
                    </label>
                </div>
            )}
        </li>
    );
}

export default function Index({ date, today, roster, canManage, statuses }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;
    const [drafts, setDrafts] = useState({});
    const [saving, setSaving] = useState(false);
    const [correctionRow, setCorrectionRow] = useState(null);

    const setDraft = (employeeId, value) => setDrafts((d) => ({ ...d, [employeeId]: value }));
    const unmarked = roster.filter((r) => !r.status);
    const marked = roster.filter((r) => r.status);
    const readyCount = unmarked.filter((r) => drafts[r.employee_id]?.status).length;

    const save = () => {
        const records = unmarked.filter((r) => drafts[r.employee_id]?.status).map((r) => ({
            employee_id: r.employee_id,
            status: drafts[r.employee_id].status,
            check_in: drafts[r.employee_id].check_in || null,
            check_out: drafts[r.employee_id].check_out || null,
            overtime_minutes: drafts[r.employee_id].overtime_minutes || null,
        }));
        setSaving(true);
        router.post(route('staff-attendance.store'), { date, records }, {
            preserveScroll: true,
            onSuccess: () => setDrafts({}),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('staff_attendance.title')}</h2>}>
            <Page
                title={t('staff_attendance.title')}
                description={t('staff_attendance.desc')}
                back={<BackLink big={auth.role === 'teacher'} href={route('dashboard')}>{t('common.back')}</BackLink>}
            >
                <FlashMessages />

                <Card>
                    <label className="block text-xs font-medium uppercase text-stone-500">{t('staff_attendance.date')}</label>
                    <DateInput
                        className="mt-1"
                        value={date}
                        onChange={(v) => router.get(route('staff-attendance.index'), { date: v }, { preserveState: true })}
                    />
                </Card>

                {roster.length === 0 && <Card><p className="text-base text-stone-600 dark:text-stone-400">{t('staff_attendance.no_employees')}</p></Card>}

                {unmarked.length > 0 && canManage && (
                    <Card>
                        <h3 className="text-base font-semibold text-stone-900 dark:text-stone-100">{t('staff_attendance.not_yet_marked', { count: unmarked.length })}</h3>
                        <ul className="mt-3 space-y-3">
                            {unmarked.map((r) => (
                                <UnmarkedRow key={r.employee_id} row={r} draft={drafts[r.employee_id]} onChange={setDraft} t={t} />
                            ))}
                        </ul>
                        <div className="sticky bottom-0 z-10 -mx-4 mt-4 border-t-2 border-stone-200 bg-white/95 px-4 py-3 backdrop-blur dark:border-stone-700 dark:bg-stone-900/95 sm:mx-0 sm:rounded-2xl sm:border-2">
                            <Button onClick={save} disabled={readyCount === 0} busy={saving} data-testid="save-attendance">
                                {t('staff_attendance.save_button', { count: readyCount })}
                            </Button>
                        </div>
                    </Card>
                )}

                {marked.length > 0 && (
                    <Card>
                        <h3 className="text-base font-semibold text-stone-900 dark:text-stone-100">{t('staff_attendance.already_marked')}</h3>
                        <ul className="mt-3 divide-y divide-stone-200 dark:divide-stone-700">
                            {marked.map((r) => (
                                <li key={r.employee_id} className="py-3" data-testid="attendance-row" data-state="marked">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <span className="text-base font-semibold text-stone-900 dark:text-stone-100">{r.full_name}</span>
                                            <StatusBadge tone={STATUS_TONE[r.status]} label={t(`staff_attendance.status_${r.status}`)} className="ml-2" />
                                        </div>
                                        {canManage && (
                                            r.pending_correction ? (
                                                <span className="text-sm text-amber-700 dark:text-amber-400" data-testid="correction-pending">{t('staff_attendance.correction_pending')}</span>
                                            ) : (
                                                <Button size="sm" variant="quiet" onClick={() => setCorrectionRow(r)}>{t('staff_attendance.request_correction')}</Button>
                                            )
                                        )}
                                    </div>
                                    <p className="text-sm text-stone-600 dark:text-stone-400">
                                        {[r.check_in && t('staff_attendance.check_in_x', { time: r.check_in }), r.check_out && t('staff_attendance.check_out_x', { time: r.check_out }), r.overtime_minutes && t('staff_attendance.overtime_x', { minutes: r.overtime_minutes })].filter(Boolean).join(' · ')}
                                        {r.recorded_by && ` · ${t('staff_attendance.recorded_by_x', { name: r.recorded_by })}`}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
            </Page>

            <CorrectionDialog
                key={correctionRow?.attendance_id ?? 'none'}
                open={correctionRow !== null}
                row={correctionRow}
                statuses={statuses}
                onClose={() => setCorrectionRow(null)}
            />
        </AuthenticatedLayout>
    );
}
