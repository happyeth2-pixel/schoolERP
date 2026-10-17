import TimetableSlotDialog from '@/Components/TimetableSlotDialog';
import { Button, Card, EmptyState, FlashMessages, Page, Select, StatusBadge, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { CalendarDaysIcon, PlusIcon } from '@heroicons/react/24/outline';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

const DAYS = [1, 2, 3, 4, 5, 6, 7];

export default function Index({
    canManage, canOverride, terms, termId, scope, entityId, periods, sections, teachers, rooms, subjects, slots,
}) {
    const { t } = useTranslation();
    const [dialog, setDialog] = useState(null); // null | 'new' | a slot object
    const [ask, confirmDialog] = useConfirm();

    const entities = scope === 'teacher' ? teachers : scope === 'room' ? rooms : sections;

    const navigate = (next) => {
        router.get(route('timetable.index'), { scope, term_id: termId, entity_id: entityId, ...next }, { preserveState: true, preserveScroll: true });
    };

    const remove = async (slot) => {
        if (await ask({
            title: t('timetable.remove_slot_title'),
            body: t('timetable.remove_slot_body'),
            confirmLabel: t('timetable.remove_slot_confirm'),
            tone: 'danger',
        })) {
            router.delete(route('timetable.destroy', slot.id), { preserveScroll: true });
        }
    };

    const cellsFor = (periodId, day) => slots.filter((s) => s.period_id === periodId && s.day_of_week === day);

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('timetable.title')}</h2>}>
            <Page
                title={t('timetable.title')}
                description={t('timetable.desc')}
                actions={canManage && entityId && <Button icon={PlusIcon} onClick={() => setDialog('new')}>{t('timetable.add_slot_button')}</Button>}
            >
                <FlashMessages errorKeys={['delete']} />

                <Card>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Select data-testid="scope-select" value={scope} onChange={(e) => navigate({ scope: e.target.value, entity_id: '' })}>
                            <option value="section">{t('timetable.scope_section')}</option>
                            <option value="teacher">{t('timetable.scope_teacher')}</option>
                            <option value="room">{t('timetable.scope_room')}</option>
                        </Select>
                        <Select data-testid="entity-select" value={entityId ?? ''} onChange={(e) => navigate({ entity_id: e.target.value })}>
                            <option value="" disabled>{t('timetable.pick_option')}</option>
                            {entities.map((x) => <option key={x.id} value={x.id}>{x.label ?? x.name}</option>)}
                        </Select>
                        <Select data-testid="term-select" value={termId ?? ''} onChange={(e) => navigate({ term_id: e.target.value })}>
                            {terms.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
                        </Select>
                    </div>
                </Card>

                {!entityId ? (
                    <Card><EmptyState icon={CalendarDaysIcon} title={t('timetable.pick_to_view_title')}>{t('timetable.pick_to_view_body')}</EmptyState></Card>
                ) : (
                    <Card>
                        <div className="overflow-x-auto">
                            <table className="w-full border-collapse text-left text-sm" data-testid="timetable-grid">
                                <thead>
                                    <tr>
                                        <th className="border border-gray-200 p-2 dark:border-gray-700" />
                                        {DAYS.map((d) => (
                                            <th key={d} className="border border-gray-200 p-2 text-gray-700 dark:border-gray-700 dark:text-gray-300">{t(`timetable.day_${d}`)}</th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {periods.map((p) => (
                                        <tr key={p.id}>
                                            <td className="border border-gray-200 p-2 font-medium text-gray-700 dark:border-gray-700 dark:text-gray-300">{p.label}</td>
                                            {DAYS.map((d) => {
                                                const cells = cellsFor(p.id, d);
                                                return (
                                                    <td key={d} className="border border-gray-200 p-2 align-top dark:border-gray-700" data-testid="timetable-cell">
                                                        {cells.map((cell) => (
                                                            // An authorized override can leave two slots clashing in the same cell
                                                            // (same teacher/room/section double-booked on purpose) -- show every one
                                                            // of them, never just the first, or the other commitment goes invisible.
                                                            <div key={cell.id} data-testid="timetable-slot" className="mb-2 last:mb-0">
                                                                <p className="font-semibold text-gray-900 dark:text-gray-100">{cell.subject}</p>
                                                                <p className="text-xs text-gray-600 dark:text-gray-400">
                                                                    {scope !== 'section' && `${cell.section} · `}
                                                                    {scope !== 'teacher' && `${cell.teacher} · `}
                                                                    {cell.room ?? t('timetable.no_room')}
                                                                </p>
                                                                {cell.has_override && <StatusBadge tone="warning" label={t('timetable.overridden_badge')} className="mt-1" />}
                                                                {canManage && (
                                                                    <div className="mt-1 flex gap-2">
                                                                        <Button size="sm" variant="quiet" onClick={() => setDialog(cell)}>{t('timetable.edit_button')}</Button>
                                                                        <Button size="sm" variant="quiet" onClick={() => remove(cell)}>{t('timetable.remove_button')}</Button>
                                                                    </div>
                                                                )}
                                                            </div>
                                                        ))}
                                                        {cells.length === 0 && canManage && (
                                                            <button type="button" className="text-sm text-indigo-600 hover:underline dark:text-indigo-400" onClick={() => setDialog({ day_of_week: d, period_id: p.id, ...(scope === 'section' ? { section_id: entityId } : scope === 'teacher' ? { teacher_id: entityId } : { room_id: entityId }) })}>
                                                                {t('timetable.add_here')}
                                                            </button>
                                                        )}
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Card>
                )}
            </Page>
            {canManage && (
                <TimetableSlotDialog
                    key={dialog === 'new' || dialog === null ? 'new' : `slot-${dialog.id ?? `${dialog.day_of_week}-${dialog.period_id}`}`}
                    open={dialog !== null}
                    slot={dialog && dialog.id ? dialog : null}
                    prefill={dialog && !dialog.id && dialog !== 'new' ? dialog : null}
                    terms={terms}
                    sections={sections}
                    subjects={subjects}
                    teachers={teachers}
                    rooms={rooms}
                    periods={periods}
                    defaultTermId={termId}
                    canOverride={canOverride}
                    onClose={() => setDialog(null)}
                />
            )}
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
