import { Alert, Button, CheckRow } from '@/Components/ui';
import { BriefcaseIcon, KeyIcon } from '@heroicons/react/24/outline';
import { useTranslation } from 'react-i18next';

// Shared by the "Add a staff member" wizard and the "Change" form on the staff list. Two ideas are kept apart on
// purpose, because mixing them up is the classic mistake on this screen:
//   - JOB TITLE  ("Vice Principal", "Registrar")  -- only a label printed next to a name; it gives no access.
//   - ROLE + PERMISSIONS                          -- what the person can actually open and do.
// Nothing here decides anything: the server still clamps permissions to what the person adding them holds.

export const ROLE_ORDER = ['branch_admin', 'teacher', 'accountant', 'auditor', 'owner'];

/** The job title vs. system access explanation, one place so it always says the same thing. */
export function TitleVersusAccess({ compact = false }) {
    const { t } = useTranslation();

    return (
        <div className="grid gap-3 sm:grid-cols-2" data-testid="title-vs-access">
            <div className="flex gap-3 rounded-xl border-2 border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40">
                <BriefcaseIcon className="h-7 w-7 shrink-0 text-gray-600 dark:text-gray-300" aria-hidden="true" />
                <div>
                    <p className="text-base font-semibold text-gray-900 dark:text-gray-100">{t('staff.vs_title_head')}</p>
                    <p className="text-sm text-gray-700 dark:text-gray-300">{t('staff.vs_title_body')}</p>
                </div>
            </div>
            <div className="flex gap-3 rounded-xl border-2 border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-800 dark:bg-indigo-900/20">
                <KeyIcon className="h-7 w-7 shrink-0 text-indigo-700 dark:text-indigo-300" aria-hidden="true" />
                <div>
                    <p className="text-base font-semibold text-gray-900 dark:text-gray-100">{t('staff.vs_access_head')}</p>
                    <p className="text-sm text-gray-700 dark:text-gray-300">{compact ? t('staff.vs_access_body_short') : t('staff.vs_access_body')}</p>
                </div>
            </div>
        </div>
    );
}

/**
 * Every permission, grouped and in words. `value` is the list of ticked permission names. A permission the
 * person adding cannot themselves hold is greyed out and says why (nobody can hand out what they do not have).
 */
export function PermissionPicker({ groups, value, onChange, myPermissions, defaults = null, readOnly = false }) {
    const { t } = useTranslation();
    const mine = new Set(myPermissions);
    const ticked = new Set(value);

    const toggle = (name, on) => onChange(on ? [...new Set([...value, name])] : value.filter((p) => p !== name));
    const all = Object.values(groups).flat();
    const usual = defaults ? defaults.filter((p) => mine.has(p)) : null;

    return (
        <div className="space-y-5" data-testid="permission-picker">
            {!readOnly && (
                <div className="flex flex-wrap items-center gap-3">
                    <p className="text-base font-semibold text-gray-900 dark:text-gray-100" data-testid="perm-count">{t('staff.perm_count', { count: value.length, total: all.filter((p) => mine.has(p)).length })}</p>
                    {usual && <Button variant="secondary" size="md" onClick={() => onChange(usual)}>{t('staff.perm_usual')}</Button>}
                    <Button variant="quiet" size="md" onClick={() => onChange([])}>{t('staff.perm_none')}</Button>
                </div>
            )}
            {Object.entries(groups).map(([group, names]) => {
                const n = names.filter((p) => ticked.has(p)).length;

                return (
                    <fieldset key={group} className="space-y-2" data-testid={`perm-group-${group}`}>
                        <legend className="mb-1 flex w-full items-baseline justify-between gap-3">
                            <span className="text-lg font-semibold text-gray-900 dark:text-gray-100">{t(`perm.group_${group}`)}</span>
                            <span className="text-sm text-gray-600 dark:text-gray-400">{t('staff.perm_group_count', { on: n, total: names.length })}</span>
                        </legend>
                        {names.map((name) => {
                            const cannot = !mine.has(name);

                            return (
                                <CheckRow
                                    key={name}
                                    id={`perm-${name}`}
                                    checked={ticked.has(name)}
                                    disabled={readOnly || cannot}
                                    onChange={(on) => toggle(name, on)}
                                    label={t(`perm.${name}`)}
                                    hint={cannot && !readOnly ? `${t(`perm.${name}_hint`)} ${t('staff.perm_cannot_give')}` : t(`perm.${name}_hint`)}
                                />
                            );
                        })}
                    </fieldset>
                );
            })}
        </div>
    );
}

/** A short, readable list of what a set of permissions allows (used on the review step and the list). */
export function PermissionSummary({ names }) {
    const { t } = useTranslation();
    if (names.length === 0) return <p className="text-base text-gray-700 dark:text-gray-300">{t('staff.perm_summary_none')}</p>;

    return (
        <ul className="list-disc space-y-0.5 pl-5 text-base text-gray-900 dark:text-gray-100" data-testid="perm-summary">
            {names.map((n) => <li key={n}>{t(`perm.${n}`)}</li>)}
        </ul>
    );
}

/** The person is adding someone whose permissions they cannot all see through: say so, never fail silently. */
export function CannotManageNote({ names }) {
    const { t } = useTranslation();
    if (names.length === 0) return null;

    return (
        <Alert tone="warning" title={t('staff.cannot_title')}>
            {t('staff.cannot_body', { count: names.length })}
        </Alert>
    );
}
