import { Alert, CheckRow, ChoiceCard, EmptyState, Field, FOCUS, Input, StatusBadge } from '@/Components/ui';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

// Choosing a class as big cards instead of a dropdown: each shows the grade and section, the class teacher and
// how many places are left, so the person never has to guess. Used by the enrolment wizard and by "move to another
// class" on the profile. It shows only what is needed to choose (no teacher contact details, no student names).
//
// Capacity rules are unchanged and still enforced by the server (SectionCapacity):
//   - a FULL class cannot be picked by someone who may not override capacity (the card is disabled and says why);
//   - someone who may override can pick it, and must then tick the box and give a reason (kept in the audit log).
// Many classes: pick the grade first (a row of large buttons), then see that grade's sections.

const MANY = 8;

export default function SectionPicker({ sections, value, onChange, canOverride, overrideChecked, overrideReason, onOverrideChecked, onOverrideReason, personName, errors = {}, label, initialGrade }) {
    const { t } = useTranslation();

    const grades = useMemo(() => {
        const seen = new Map();
        sections.forEach((s) => seen.set(s.grade, s.grade_order ?? 0));
        return [...seen.entries()].sort((a, b) => a[1] - b[1]).map(([name]) => name);
    }, [sections]);

    const selected = sections.find((s) => String(s.id) === String(value));
    const [grade, setGrade] = useState(selected?.grade ?? initialGrade ?? '');
    const grouped = sections.length > MANY;
    const visible = grouped ? sections.filter((s) => s.grade === grade) : sections;

    if (sections.length === 0) {
        return <EmptyState title={t('enroll.no_classes_title')}>{t('enroll.no_classes_body')}</EmptyState>;
    }

    const seats = (s) => {
        if (s.capacity == null) return t('enroll.no_limit');
        return s.full ? t('enroll.full') : t('enroll.seats_left', { left: s.seats_left, capacity: s.capacity });
    };

    return (
        <div className="space-y-4">
            {grouped && (
                <div>
                    <p className="mb-2 text-base font-medium text-gray-900 dark:text-gray-100">{t('enroll.which_grade')}</p>
                    <div className="flex flex-wrap gap-2" role="radiogroup" aria-label={t('enroll.which_grade')}>
                        {grades.map((g) => (
                            <button
                                key={g}
                                type="button"
                                role="radio"
                                aria-checked={grade === g}
                                onClick={() => setGrade(g)}
                                className={`min-h-[56px] min-w-[72px] rounded-xl border-2 px-4 text-base font-semibold ${FOCUS} ${grade === g ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 bg-white text-gray-900 hover:border-indigo-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100'}`}
                            >
                                {g}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {grouped && !grade ? (
                <p className="text-base text-gray-600 dark:text-gray-400">{t('enroll.pick_grade_first')}</p>
            ) : (
                <div role="radiogroup" aria-label={label ?? t('enroll.pick_class')} className="space-y-3">
                    {visible.map((s) => {
                        const blocked = s.full && !canOverride;
                        return (
                            <ChoiceCard
                                key={s.id}
                                selected={String(value) === String(s.id)}
                                disabled={blocked}
                                onSelect={() => onChange(s.id)}
                                title={s.label}
                                badge={s.recommended && <StatusBadge tone="success" label={t('enroll.recommended_badge')} />}
                                hint={`${s.teacher ? t('enroll.teacher', { name: s.teacher }) : t('enroll.no_teacher')} · ${seats(s)}${blocked ? ` — ${t('enroll.full_ask_admin')}` : ''}${s.recommended ? ` · ${t('enroll.recommended_reason', { count: s.occupied })}` : ''}`}
                            />
                        );
                    })}
                </div>
            )}

            {errors.section_id && <Alert tone="danger">{errors.section_id}</Alert>}

            {selected?.full && canOverride && (
                <Alert tone="warning" title={t('enroll.full_title', { label: selected.label })}>
                    <p className="mb-3">{t('enroll.full_body', { capacity: selected.capacity })}</p>
                    <div className="space-y-3">
                        <CheckRow checked={!!overrideChecked} onChange={onOverrideChecked} label={t('enroll.override_label', { name: personName })} />
                        {overrideChecked && (
                            <Field id="override_reason" label={t('enroll.override_reason')} hint={t('enroll.override_hint')} error={errors.override_reason}>
                                <Input value={overrideReason ?? ''} onChange={(e) => onOverrideReason(e.target.value)} />
                            </Field>
                        )}
                    </div>
                </Alert>
            )}
        </div>
    );
}
