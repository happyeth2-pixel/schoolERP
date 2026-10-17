import DateInput from '@/Components/DateInput';
import DateText from '@/Components/DateText';
import { BackLink, Button, Card, ChoiceGroup, Field, Input, Page, ReviewList, SuccessPanel, TextArea, WizardShell, useWizard } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Link, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

// Writing an announcement, as three short steps: WHAT to say, WHO gets it and WHEN, then a check that shows the
// message the way it will be read. It posts the same fields to the same endpoint as before, which keeps the rules:
// branch staff post to their own branch only, only org-level accounts may post school-wide, and a publish date
// cannot be in the past. Nothing is preselected for who and when, because an announcement reaches many people.

const nextDay = (iso) => { const d = new Date(`${iso}T00:00:00Z`); d.setUTCDate(d.getUTCDate() + 1); return d.toISOString().slice(0, 10); };

export default function Wizard({ audiences, branches, canPostSchoolWide, today, saved }) {
    const { t } = useTranslation();
    const tomorrow = nextDay(today);
    const fixedBranch = !canPostSchoolWide;

    const form = useForm({ title: '', body: '', audience: '', branch: fixedBranch ? String(branches[0]?.id ?? '') : '', when: '', publish_on: tomorrow });
    const { data, setData, errors, processing, clearErrors } = form;
    const set = (k) => (v) => { clearErrors(k); setData(k, v); };
    const [dismissed, setDismissed] = useState(false);
    useEffect(() => { if (saved) setDismissed(false); }, [saved]);

    const branch = branches.find((b) => String(b.id) === data.branch);
    const branchName = data.branch === '' || data.branch === 'all' ? t('ann.all_branches') : branch?.name ?? '';
    const branchDecided = fixedBranch || data.branch !== '';
    const later = data.when === 'later';
    const dateOk = !later || (data.publish_on && data.publish_on > today);
    const titleT = data.title.trim();
    const bodyT = data.body.trim();

    const steps = [
        { key: 'write', label: t('ann.step_write'), description: t('ann.step_write_desc'), fields: ['title', 'body'], isValid: () => titleT !== '' && bodyT !== '', whyBlocked: t('ann.why_write') },
        { key: 'who', label: t('ann.step_who'), description: t('ann.step_who_desc'), fields: ['audience', 'branch_id', 'publish_on'], isValid: () => data.audience !== '' && branchDecided && data.when !== '' && dateOk, whyBlocked: data.audience === '' ? t('ann.why_audience') : !branchDecided ? t('ann.why_branch') : data.when === '' ? t('ann.why_when') : t('ann.why_date') },
        { key: 'review', label: t('ann.step_review'), description: t('ann.step_review_desc') },
    ];

    const wizard = useWizard({
        steps,
        errors,
        onSubmit: () => {
            form.transform((d) => ({ title: d.title.trim(), body: d.body.trim(), audience: d.audience, branch_id: d.branch === '' || d.branch === 'all' ? null : d.branch, publish_on: later ? d.publish_on : today }));
            form.post(route('announcements.store'), { preserveScroll: true });
        },
    });
    const key = wizard.step.key;
    const stepOf = (k) => steps.findIndex((s) => s.key === k);

    const title = t('ann.title_add');
    const header = <h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{title}</h2>;
    const back = <BackLink href={route('announcements.index')}>{t('ann.back')}</BackLink>;

    if (saved && !dismissed) {
        return (
            <AuthenticatedLayout header={header}>
                <Page title={title} width="default">
                    <SuccessPanel
                        title={saved.scheduled ? t('ann.saved_scheduled') : t('ann.saved_published')}
                        details={<p className="text-base text-emerald-950 dark:text-emerald-50">{saved.scheduled ? t('ann.saved_note_scheduled', { audience: t(`ann.aud_${saved.audience}_short`) }) : t('ann.saved_note_now', { audience: t(`ann.aud_${saved.audience}_short`) })}</p>}
                        actions={
                            <div className="flex w-full flex-col gap-3">
                                <Button fullWidth onClick={() => { setDismissed(true); form.clearErrors(); form.setData({ title: '', body: '', audience: '', branch: fixedBranch ? String(branches[0]?.id ?? '') : '', when: '', publish_on: tomorrow }); wizard.reset(); }}>{t('ann.write_another')}</Button>
                                <Button as={Link} href={route('announcements.index')} variant="quiet" fullWidth>{t('ann.see_all')}</Button>
                            </div>
                        }
                    >
                        {saved.title}
                    </SuccessPanel>
                </Page>
            </AuthenticatedLayout>
        );
    }

    return (
        <AuthenticatedLayout header={header}>
            <Page title={title} description={t('ann.desc_add')} width="default" back={back}>
                <Card>
                    <WizardShell wizard={wizard} finalLabel={later ? t('ann.final_schedule') : t('ann.final_publish')} processing={processing}>
                        {key === 'write' && (
                            <div className="space-y-6">
                                <Field id="ann_title" label={t('ann.title_label')} hint={t('ann.title_hint')} error={errors.title}>
                                    <Input value={data.title} onChange={(e) => set('title')(e.target.value)} autoFocus autoComplete="off" maxLength={150} />
                                </Field>
                                <Field id="ann_body" label={t('ann.body_label')} hint={t('ann.body_hint')} error={errors.body}>
                                    <TextArea rows={7} value={data.body} onChange={(e) => set('body')(e.target.value)} maxLength={5000} />
                                </Field>
                            </div>
                        )}

                        {key === 'who' && (
                            <div className="space-y-7">
                                <ChoiceGroup
                                    heading={t('ann.q_audience')}
                                    value={data.audience}
                                    onChange={set('audience')}
                                    options={audiences.map((a) => ({ value: a, label: t(`ann.aud_${a}`), hint: t(`ann.aud_${a}_hint`) }))}
                                />
                                {errors.audience && <p className="-mt-4 text-sm font-medium text-red-700">{errors.audience}</p>}

                                {fixedBranch ? (
                                    <p className="rounded-xl bg-gray-50 p-4 text-base text-gray-800 dark:bg-gray-900/40 dark:text-gray-200" data-testid="fixed-branch">{t('ann.fixed_branch', { branch: branches[0]?.name })}</p>
                                ) : (
                                    <ChoiceGroup
                                        heading={t('ann.q_branch')}
                                        value={data.branch}
                                        onChange={set('branch')}
                                        options={[{ value: 'all', label: t('ann.all_branches'), hint: t('ann.all_branches_hint') }, ...branches.map((b) => ({ value: String(b.id), label: b.name, hint: t('ann.one_branch_hint') }))]}
                                    />
                                )}

                                <div>
                                    <ChoiceGroup
                                        heading={t('ann.q_when')}
                                        value={data.when}
                                        onChange={set('when')}
                                        options={[{ value: 'now', label: t('ann.when_now'), hint: t('ann.when_now_hint') }, { value: 'later', label: t('ann.when_later_label'), hint: t('ann.when_later_hint') }]}
                                    />
                                    {later && (
                                        <div className="mt-4">
                                            <Field id="ann_date" label={t('ann.date_label')} error={errors.publish_on ?? (data.publish_on && data.publish_on <= today ? t('ann.err_date') : undefined)}>
                                                <DateInput large min={tomorrow} value={data.publish_on} onChange={(v) => set('publish_on')(v)} />
                                            </Field>
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}

                        {key === 'review' && (
                            <div className="space-y-5">
                                <div className="rounded-2xl border-2 border-gray-300 bg-white p-5 dark:border-gray-600 dark:bg-gray-900/40" data-testid="preview">
                                    <p className="text-sm font-medium uppercase tracking-wide text-gray-600 dark:text-gray-400">{t('ann.preview')}</p>
                                    <h3 className="mt-1 text-xl font-bold text-gray-900 dark:text-gray-100">{titleT}</h3>
                                    <p className="mt-2 whitespace-pre-line text-base text-gray-800 dark:text-gray-200">{bodyT}</p>
                                </div>
                                <ReviewList
                                    onEdit={wizard.goTo}
                                    rows={[
                                        { label: t('ann.r_audience'), value: data.audience ? t(`ann.aud_${data.audience}_short`) : '', step: stepOf('who') },
                                        { label: t('ann.r_branch'), value: branchName, step: fixedBranch ? undefined : stepOf('who') },
                                        { label: t('ann.r_when'), value: later ? <DateText value={data.publish_on} /> : t('ann.when_now'), step: stepOf('who') },
                                    ]}
                                />
                                <p className="text-base text-gray-700 dark:text-gray-300">{later ? t('ann.review_note_later') : t('ann.review_note_now')}</p>
                            </div>
                        )}
                    </WizardShell>
                </Card>
            </Page>
        </AuthenticatedLayout>
    );
}
