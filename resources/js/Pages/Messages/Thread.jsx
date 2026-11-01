import AttachmentGrid from '@/Components/AttachmentGrid';
import AttachmentPicker from '@/Components/AttachmentPicker';
import Avatar from '@/Components/Avatar';
import { Alert, BackLink, Button, Card, EmptyState, Field, FlashMessages, Page, TextArea } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

// A private, per-student thread between the family and whoever is currently responsible for the class --
// the homeroom teacher, a branch admin, or the owner (see StudentPolicy::sendMessage). Nothing here is a
// class-wide broadcast: that's its own action, "message the whole class", reached from Attendance for a
// homeroom teacher with today's roster already open (e.g. to pass on homework).

export default function Thread({ student, contact, messages, canSend, backTo }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset } = useForm({ body: '', images: [] });
    const backHref = route(backTo.route, backTo.params);
    const backLabel = {
        'messages.index': t('messages.back_to_inbox'),
        'students.edit': t('messages.back_to_profile'),
        dashboard: t('messages.back_to_home'),
    }[backTo.route] ?? t('messages.back_to_home');

    // A parent already knows their own child's name -- what they actually want in the heading is who
    // they're talking to. A teacher/admin viewing this same thread IS the contact, so they keep seeing
    // the student's name instead (see ParentMessageController::contactFor).
    const headingName = contact ? (contact.role === 'homeroom_teacher' ? contact.name : t('messages.school_office_label')) : student.name;
    const headingDesc = contact
        ? [contact.role === 'homeroom_teacher' ? t('messages.role_homeroom_teacher') : t('messages.no_homeroom_teacher_yet'), t('messages.for_student', { name: student.name })].filter(Boolean).join(' · ')
        : student.class;

    const submit = (e) => {
        e.preventDefault();
        post(route('students.messages.store', student.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => reset('body', 'images'),
        });
    };

    return (
        <AuthenticatedLayout header={<h2 className="truncate text-lg font-semibold text-stone-800 dark:text-stone-200">{t('messages.page_title')}</h2>}>
            <Page
                title={contact ? t('messages.title_with', { name: headingName }) : t('messages.title_for', { name: headingName })}
                description={headingDesc}
                actions={contact ? <Avatar src={contact.photo_url} name={headingName} size={48} fallback="initials" /> : undefined}
                width="narrow"
                back={<BackLink href={backHref}>{backLabel}</BackLink>}
            >
                <FlashMessages />
                <Alert tone="info">{t('messages.private_note')}</Alert>

                <Card>
                    {messages.length === 0 ? (
                        <EmptyState title={t('messages.none_yet_title')}>{t('messages.none_yet_body')}</EmptyState>
                    ) : (
                        <ul className="space-y-4" data-testid="message-list">
                            {messages.map((m) => (
                                <li key={m.id} data-testid="message-row" className={`flex gap-3 ${m.mine ? 'flex-row-reverse' : ''}`}>
                                    <Avatar src={m.sender_photo_url} name={m.sender_name} size={36} fallback="initials" className="shrink-0" />
                                    <div className={`max-w-[80%] rounded-xl p-4 ${m.mine ? 'bg-brand-50 dark:bg-brand-900/30' : 'bg-stone-50 dark:bg-stone-900/40'}`}>
                                        <p className="mb-1 flex items-baseline justify-between gap-3 text-sm font-semibold text-stone-700 dark:text-stone-300">
                                            <span>{m.sender_name}</span>
                                            <time className="shrink-0 font-normal text-stone-500 dark:text-stone-400">{new Date(m.sent_at).toLocaleString()}</time>
                                        </p>
                                        <p className="whitespace-pre-wrap text-base text-stone-900 dark:text-stone-100">{m.body}</p>
                                        <AttachmentGrid images={m.images} />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                {canSend && (
                    <form onSubmit={submit}>
                        <Card>
                            <Field id="message_body" label={t('messages.write_label')} error={errors.body}>
                                <TextArea value={data.body} onChange={(e) => setData('body', e.target.value)} rows={3} maxLength={2000} autoFocus />
                            </Field>
                            <div className="mt-3">
                                <AttachmentPicker value={data.images} onChange={(images) => setData('images', images)} error={errors.images} busy={processing} />
                            </div>
                            <div className="mt-4 flex justify-end">
                                <Button type="submit" busy={processing} disabled={data.body.trim() === ''}>{t('messages.send_button')}</Button>
                            </div>
                        </Card>
                    </form>
                )}
            </Page>
        </AuthenticatedLayout>
    );
}
