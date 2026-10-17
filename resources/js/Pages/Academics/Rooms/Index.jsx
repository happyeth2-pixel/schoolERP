import RoomDialog from '@/Components/RoomDialog';
import { Button, Card, EmptyState, FlashMessages, Page, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BuildingOffice2Icon, PlusIcon } from '@heroicons/react/24/outline';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

export default function Index({ rooms, canManage, branches, fixedBranch }) {
    const { t } = useTranslation();
    const [dialog, setDialog] = useState(null); // null | 'new' | a room object
    const [ask, confirmDialog] = useConfirm();

    const remove = async (room) => {
        if (await ask({
            title: t('timetable.remove_room_title', { name: room.name }),
            body: t('timetable.remove_room_body'),
            confirmLabel: t('timetable.remove_room_confirm'),
            tone: 'danger',
        })) {
            router.delete(route('rooms.destroy', room.id), { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('timetable.rooms_title')}</h2>}>
            <Page
                title={t('timetable.rooms_title')}
                description={t('timetable.rooms_desc')}
                actions={canManage && <Button icon={PlusIcon} onClick={() => setDialog('new')}>{t('timetable.add_room_button')}</Button>}
            >
                <FlashMessages errorKeys={['name']} />

                {rooms.length === 0 ? (
                    <Card><EmptyState icon={BuildingOffice2Icon} title={t('timetable.empty_rooms_title')}>{t('timetable.empty_rooms_body')}</EmptyState></Card>
                ) : (
                    <Card>
                        <ul className="divide-y divide-gray-200 dark:divide-gray-700" data-testid="room-list">
                            {rooms.map((r) => (
                                <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 py-3" data-testid="room-row">
                                    <div>
                                        <span className="text-base font-semibold text-gray-900 dark:text-gray-100">{r.name}</span>
                                        <p className="text-sm text-gray-600 dark:text-gray-400">
                                            {r.branch}{r.capacity != null && ` · ${t('timetable.room_capacity_x', { count: r.capacity })}`}
                                        </p>
                                    </div>
                                    {canManage && (
                                        <div className="flex items-center gap-3">
                                            <Button size="sm" variant="quiet" onClick={() => setDialog(r)}>{t('timetable.edit_button')}</Button>
                                            <Button size="sm" variant="quiet" onClick={() => remove(r)}>{t('timetable.remove_button')}</Button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
            </Page>
            {canManage && (
                <RoomDialog
                    key={dialog === 'new' || dialog === null ? 'new' : `edit-${dialog.id}`}
                    open={dialog !== null}
                    room={dialog === 'new' ? null : dialog}
                    branches={branches}
                    fixedBranch={fixedBranch}
                    onClose={() => setDialog(null)}
                />
            )}
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
