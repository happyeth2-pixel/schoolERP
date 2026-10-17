import { Alert, Button, Card, ChoiceGroup, CheckRow, Field, FlashMessages, Input, Page, SectionHeader, Select, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useUnsavedGuard } from '@/useUnsavedGuard';
import { TrashIcon } from '@heroicons/react/24/outline';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import reviewStatus from '@/lang/review-status.json';

// School settings, in labelled parts that can be reached from a short list at the top: school details, calendar,
// report cards, discounts and terms, languages, and (its own form) payment accounts. It is still ONE form and
// one Save for the school's own settings, sent to the same endpoint with the same rules, so nothing became
// optional or unchecked. Payment accounts have their own steps because they are added one at a time.

// One honest sentence per language from review-status.json, so an owner deciding whether to turn a language on sees
// its real, current status (see LOCALIZATION.md), not a hand-written warning that goes stale.
function languageReviewNote(code, t) {
    if (code === 'en') return null;
    const entries = Object.entries(reviewStatus[code] ?? {}).filter(([k]) => !k.startsWith('_'));
    const total = entries.length;
    if (total === 0) return null;
    const settled = entries.filter(([, status]) => status === 'trusted' || status === 'reviewed').length;
    if (settled === total) return null;
    if (settled === 0) return t('settings.lang_note_none');
    return t('settings.lang_note_some', { settled, total, rest: total - settled });
}

function PaymentAccountsManager({ accounts, bankPresets, branches, canManageSchoolWide }) {
    const { t } = useTranslation();
    const [ask, confirmDialog] = useConfirm();
    const [selectedKey, setSelectedKey] = useState('');
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        bank_key: '',
        branch_id: canManageSchoolWide ? '' : (branches[0]?.id ?? ''),
        display_name: '',
        account_number: '',
        account_name: '',
        logo: null,
    });

    const isCustom = selectedKey === 'other';
    const selectedPreset = bankPresets.find((b) => b.key === selectedKey);
    const isMobileMoney = selectedPreset?.type === 'mobile_money';

    const choosePreset = (key) => {
        clearErrors();
        setSelectedKey(key);
        setData('bank_key', key === 'other' ? '' : key);
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('payment-accounts.store'), { forceFormData: true, preserveScroll: true, onSuccess: () => { reset(); setSelectedKey(''); } });
    };

    const remove = async (a) => {
        const ok = await ask({
            title: t('settings.pay_remove_title', { name: a.display_name }),
            body: <p>{t('settings.pay_remove_body')}</p>,
            confirmLabel: t('settings.pay_remove_button', { name: a.display_name }),
        });
        if (ok) router.delete(route('payment-accounts.destroy', a.id), { preserveScroll: true });
    };

    return (
        <Card id="settings-payments" data-testid="payment-accounts">
            <SectionHeader title={t('settings.pay_title')} description={t('settings.pay_desc')} />

            {accounts.length === 0 ? (
                <Alert tone="warning" title={t('settings.pay_none_title')} className="mb-5">{t('settings.pay_none_body')}</Alert>
            ) : (
                <ul className="mb-5 grid grid-cols-[minmax(0,1fr)] gap-3 sm:grid-cols-2" data-testid="account-list">
                    {accounts.map((a) => (
                        <li key={a.id} className="flex flex-wrap items-center gap-3 rounded-xl border-2 border-gray-200 p-3 dark:border-gray-700">
                            {a.logo_url ? (
                                <img src={a.logo_url} alt="" className="h-12 w-12 shrink-0 rounded-md bg-white object-contain p-1 shadow-sm" />
                            ) : (
                                <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-md bg-indigo-100 text-lg font-bold text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">{a.display_name[0]}</span>
                            )}
                            <div className="min-w-0 flex-1 basis-40">
                                <p className="truncate text-base font-semibold text-gray-900 dark:text-gray-100">{a.display_name}</p>
                                <p className="truncate text-sm text-gray-700 dark:text-gray-300">{a.account_number}{a.account_name ? ` · ${a.account_name}` : ''}</p>
                                <p className="truncate text-sm text-indigo-700 dark:text-indigo-300">{a.branch_name ? t('settings.pay_branch_only', { branch: a.branch_name }) : t('settings.pay_all_branches')}</p>
                            </div>
                            {a.editable && <Button size="md" variant="quiet" icon={TrashIcon} onClick={() => remove(a)} aria-label={t('settings.pay_remove_button', { name: a.display_name })}>{t('settings.pay_remove')}</Button>}
                        </li>
                    ))}
                </ul>
            )}

            <form onSubmit={submit} className="space-y-6 rounded-xl border-2 border-dashed border-gray-300 p-4 dark:border-gray-600" data-testid="add-account">
                <div>
                    <p className="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{t('settings.pay_step1')}</p>
                    <div role="radiogroup" aria-label={t('settings.pay_step1')} className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        {bankPresets.map((b) => (
                            <button type="button" key={b.key} role="radio" aria-checked={selectedKey === b.key} onClick={() => choosePreset(b.key)}
                                className={`flex min-h-[56px] items-center gap-2 rounded-xl border-2 p-2 text-left text-base font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 ${selectedKey === b.key ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/30' : 'border-gray-300 hover:border-indigo-400 dark:border-gray-600'}`}>
                                <img src={b.logo_url} alt="" className="h-8 w-8 shrink-0 object-contain" />
                                <span className="text-gray-900 dark:text-gray-100">{b.label}</span>
                            </button>
                        ))}
                        <button type="button" role="radio" aria-checked={selectedKey === 'other'} onClick={() => choosePreset('other')}
                            className={`flex min-h-[56px] items-center justify-center rounded-xl border-2 p-2 text-base font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 ${selectedKey === 'other' ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/30' : 'border-gray-300 hover:border-indigo-400 dark:border-gray-600'} text-gray-900 dark:text-gray-100`}>
                            {t('settings.pay_other')}
                        </button>
                    </div>
                </div>

                {selectedKey && (
                    <div className="space-y-6 border-t-2 border-gray-100 pt-5 dark:border-gray-700">
                        <div>
                            <p className="mb-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{t('settings.pay_step2')}</p>
                            <Select id="account_branch" aria-label={t('settings.pay_step2')} value={data.branch_id} onChange={(e) => setData('branch_id', e.target.value)} disabled={!canManageSchoolWide}>
                                {canManageSchoolWide && <option value="">{t('settings.pay_all_branches')}</option>}
                                {branches.map((b) => <option key={b.id} value={b.id}>{t('settings.pay_branch_only', { branch: b.name })}</option>)}
                            </Select>
                            <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">{t('settings.pay_branch_hint')}</p>
                        </div>
                        <div className="space-y-4">
                            <p className="text-lg font-semibold text-gray-900 dark:text-gray-100">{t('settings.pay_step3')}</p>
                            {isCustom && (
                                <Field id="display_name" label={t('settings.pay_bank_name')} error={errors.display_name}>
                                    <Input value={data.display_name} onChange={(e) => setData('display_name', e.target.value)} autoComplete="off" />
                                </Field>
                            )}
                            <Field id="account_number" label={isMobileMoney ? t('settings.pay_phone') : t('settings.pay_number')} error={errors.account_number}>
                                <Input inputMode="numeric" value={data.account_number} onChange={(e) => setData('account_number', e.target.value)} autoComplete="off" />
                            </Field>
                            {!isMobileMoney && (
                                <Field id="account_name" label={t('settings.pay_account_name')} hint={t('settings.pay_account_name_hint')} optional error={errors.account_name}>
                                    <Input value={data.account_name} onChange={(e) => setData('account_name', e.target.value)} autoComplete="off" />
                                </Field>
                            )}
                            {isCustom && (
                                <Field id="logo" label={t('settings.pay_logo')} optional error={errors.logo}>
                                    <input type="file" accept=".jpg,.jpeg,.png" className="block w-full text-base text-gray-700 dark:text-gray-300" onChange={(e) => setData('logo', e.target.files[0])} />
                                </Field>
                            )}
                        </div>
                        <Button type="submit" busy={processing}>{t('settings.pay_add_button', { name: selectedPreset?.label ?? (data.display_name || t('settings.pay_this_bank')) })}</Button>
                    </div>
                )}
            </form>
            {confirmDialog}
        </Card>
    );
}

export default function School({ school, paymentAccounts, bankPresets, missingMarkPolicies, branches, canManageSchoolWideAccounts }) {
    const { t } = useTranslation();
    const [ask, confirmDialog] = useConfirm();
    const form = useForm({
        name: school.name ?? '',
        region: school.region ?? '',
        city: school.city ?? '',
        subcity: school.subcity ?? '',
        woreda: school.woreda ?? '',
        phone: school.phone ?? '',
        email: school.email ?? '',
        calendar_preference: school.calendar_preference ?? 'both',
        missing_mark_policy: school.missing_mark_policy ?? 'exclude',
        discount_limit_percent: school.discount_limit_percent ?? 10,
        waiver_limit_amount: school.waiver_limit_amount ?? 1000,
        purchase_approval_threshold: school.purchase_approval_threshold ?? '',
        allow_overlapping_terms: !!school.allow_overlapping_terms,
        enabled_languages: school.enabled_languages ?? ['en', 'am'],
    });
    const { data, setData, patch, processing, errors, isDirty, clearErrors } = form;
    const set = (k) => (v) => { clearErrors(k); setData(k, v); };

    useUnsavedGuard(isDirty, () => ask({
        tone: 'info',
        title: t('settings.leave_title'),
        body: <p>{t('settings.leave_body')}</p>,
        confirmLabel: t('settings.leave_button'),
        cancelLabel: t('settings.leave_stay'),
    }));

    const languages = [['en', 'English'], ['am', 'አማርኛ (Amharic)'], ['om', 'Afaan Oromoo (Oromo)'], ['ti', 'ትግርኛ (Tigrinya)']];
    const toggleLanguage = (code, on) => {
        clearErrors('enabled_languages');
        setData('enabled_languages', on ? [...data.enabled_languages, code] : data.enabled_languages.filter((c) => c !== code));
    };

    const submit = (e) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, purchase_approval_threshold: d.purchase_approval_threshold === '' ? null : d.purchase_approval_threshold }));
        patch(route('school-settings.update'), { preserveScroll: true });
    };

    const parts = [['details', t('settings.p_details')], ['calendar', t('settings.p_calendar')], ['reports', t('settings.p_reports')], ['money', t('settings.p_money')], ['languages', t('settings.p_languages')], ['payments', t('settings.p_payments')]];

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('settings.page_title')}</h2>}>
            <Page title={t('settings.page_title')} description={t('settings.page_desc')} width="default">
                <FlashMessages />

                <nav aria-label={t('settings.on_this_page')} data-testid="settings-nav">
                    <p className="mb-2 text-base font-semibold text-gray-900 dark:text-gray-100">{t('settings.on_this_page')}</p>
                    <ul className="flex flex-wrap gap-2">
                        {parts.map(([id, label]) => (
                            <li key={id}><a href={`#settings-${id}`} className="inline-flex min-h-[44px] items-center rounded-xl border-2 border-gray-300 bg-white px-4 text-base font-medium text-indigo-700 hover:border-indigo-400 dark:border-gray-600 dark:bg-gray-800 dark:text-indigo-300">{label}</a></li>
                        ))}
                    </ul>
                </nav>

                <form onSubmit={submit} className="space-y-6" data-testid="settings-form">
                    <Card id="settings-details">
                        <SectionHeader title={t('settings.p_details')} description={t('settings.details_desc')} />
                        <div className="space-y-5">
                            <Field id="name" label={t('settings.school_name')} error={errors.name}>
                                <Input value={data.name} onChange={(e) => set('name')(e.target.value)} autoComplete="off" />
                            </Field>
                            <div className="grid gap-5 sm:grid-cols-2">
                                <Field id="region" label={t('settings.region')} optional error={errors.region}><Input value={data.region} onChange={(e) => set('region')(e.target.value)} autoComplete="off" /></Field>
                                <Field id="city" label={t('settings.city')} optional error={errors.city}><Input value={data.city} onChange={(e) => set('city')(e.target.value)} autoComplete="off" /></Field>
                                <Field id="subcity" label={t('settings.subcity')} optional error={errors.subcity}><Input value={data.subcity} onChange={(e) => set('subcity')(e.target.value)} autoComplete="off" /></Field>
                                <Field id="woreda" label={t('settings.woreda')} optional error={errors.woreda}><Input value={data.woreda} onChange={(e) => set('woreda')(e.target.value)} autoComplete="off" /></Field>
                                <Field id="phone" label={t('settings.phone')} hint={t('settings.phone_hint')} optional error={errors.phone}><Input type="tel" inputMode="tel" value={data.phone} onChange={(e) => set('phone')(e.target.value)} autoComplete="off" /></Field>
                                <Field id="email" label={t('settings.email')} optional error={errors.email}><Input type="email" inputMode="email" value={data.email} onChange={(e) => set('email')(e.target.value)} autoComplete="off" /></Field>
                            </div>
                        </div>
                    </Card>

                    <Card id="settings-calendar">
                        <SectionHeader title={t('settings.p_calendar')} description={t('settings.calendar_desc')} />
                        <ChoiceGroup
                            label={t('settings.p_calendar')}
                            value={data.calendar_preference}
                            onChange={set('calendar_preference')}
                            options={[
                                { value: 'ethiopian', label: t('settings.cal_ethiopian'), hint: t('settings.cal_ethiopian_hint') },
                                { value: 'gregorian', label: t('settings.cal_gregorian'), hint: t('settings.cal_gregorian_hint') },
                                { value: 'both', label: t('settings.cal_both'), hint: t('settings.cal_both_hint') },
                            ]}
                        />
                    </Card>

                    <Card id="settings-reports">
                        <SectionHeader title={t('settings.p_reports')} description={t('settings.reports_desc')} />
                        <ChoiceGroup
                            label={t('settings.p_reports')}
                            value={data.missing_mark_policy}
                            onChange={set('missing_mark_policy')}
                            options={Object.entries(missingMarkPolicies ?? {}).map(([value, text]) => ({ value, label: value === 'exclude' ? t('settings.mm_exclude') : t('settings.mm_zero'), hint: text }))}
                        />
                        <p className="mt-3 text-sm font-medium text-amber-900 dark:text-amber-200">{t('settings.reports_warn')}</p>
                    </Card>

                    <Card id="settings-money">
                        <SectionHeader title={t('settings.p_money')} description={t('settings.money_desc')} />
                        <div className="grid gap-5 sm:grid-cols-2">
                            <Field id="discount_limit_percent" label={t('settings.discount_limit')} hint={t('settings.discount_limit_hint')} error={errors.discount_limit_percent}>
                                <Input inputMode="decimal" value={data.discount_limit_percent} onChange={(e) => set('discount_limit_percent')(e.target.value)} autoComplete="off" />
                            </Field>
                            <Field id="waiver_limit_amount" label={t('settings.waiver_limit')} hint={t('settings.waiver_limit_hint')} error={errors.waiver_limit_amount}>
                                <Input inputMode="decimal" value={data.waiver_limit_amount} onChange={(e) => set('waiver_limit_amount')(e.target.value)} autoComplete="off" />
                            </Field>
                            <Field id="purchase_approval_threshold" label={t('settings.purchase_threshold')} optional hint={t('settings.purchase_threshold_hint')} error={errors.purchase_approval_threshold}>
                                <Input inputMode="decimal" value={data.purchase_approval_threshold} onChange={(e) => set('purchase_approval_threshold')(e.target.value)} autoComplete="off" />
                            </Field>
                        </div>
                        <div className="mt-5">
                            <CheckRow id="allow_overlapping_terms" checked={data.allow_overlapping_terms} onChange={(on) => set('allow_overlapping_terms')(on)} label={t('settings.overlap_label')} hint={t('settings.overlap_hint')} />
                        </div>
                    </Card>

                    <Card id="settings-languages">
                        <SectionHeader title={t('settings.p_languages')} description={t('settings.languages_desc')} />
                        <div className="space-y-3">
                            {languages.map(([code, label]) => {
                                const on = data.enabled_languages.includes(code);
                                const note = languageReviewNote(code, t);
                                const onlyOne = on && data.enabled_languages.length === 1;

                                return (
                                    <CheckRow key={code} id={`lang-${code}`} checked={on} disabled={onlyOne} onChange={(v) => toggleLanguage(code, v)} label={label}
                                        hint={[onlyOne ? t('settings.lang_at_least_one') : null, note].filter(Boolean).join(' ') || undefined} />
                                );
                            })}
                        </div>
                        {errors.enabled_languages && <p role="alert" className="mt-2 text-sm font-medium text-red-700 dark:text-red-400">{errors.enabled_languages}</p>}
                    </Card>

                    <div className="fixed inset-x-0 bottom-0 z-20 border-t-2 border-gray-200 bg-white/95 py-3 backdrop-blur dark:border-gray-700 dark:bg-gray-900/95" data-testid="save-bar">
                        <div className="mx-auto flex max-w-3xl flex-col gap-2 px-4 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                            <p className={`text-base font-medium ${isDirty ? 'text-amber-900 dark:text-amber-200' : 'text-gray-600 dark:text-gray-400'}`} data-testid="dirty-note">{isDirty ? t('settings.unsaved') : t('settings.all_saved')}</p>
                            <Button type="submit" busy={processing} disabled={!isDirty}>{t('settings.save_button')}</Button>
                        </div>
                    </div>
                </form>

                <PaymentAccountsManager accounts={paymentAccounts} bankPresets={bankPresets} branches={branches} canManageSchoolWide={canManageSchoolWideAccounts} />
                <div className="h-28" aria-hidden="true" />
            </Page>
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
