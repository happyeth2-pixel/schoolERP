import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Button from './Button';

export { ChoiceCard } from './Choices'; // kept importable from here: the enrolment wizard's cards live with the wizard vocabulary

// The guided, one-decision-at-a-time pattern, extracted from the enrolment wizard and the parent's
// payment-proof wizard. A wizard is:
//
//   const steps = [
//     { key: 'method', label: 'How did you pay?', description: 'Choose one.',
//       fields: ['method'],                     // server-side error keys that belong to this step
//       isValid: () => data.method !== '',      // Next stays off until this is true
//       whyBlocked: 'Choose how you paid to continue.' },   // shown next to the disabled Next
//     ...,
//     { key: 'review', label: 'Check and send', ... },      // the last step is ALWAYS a review
//   ];
//   const wizard = useWizard({ steps, errors, onSubmit: () => post(...) });
//   <WizardShell wizard={wizard} finalLabel="Send to school" processing={processing}>
//       {wizard.step.key === 'method' && ...}
//   </WizardShell>
//
// Rules the pattern enforces, so each new wizard gets them for free:
//   - "Step X of Y", a segmented bar and the step's title, always visible.
//   - At most a few decisions per step (that part is up to the author -- keep steps small).
//   - Next is disabled until the step is valid, and says WHY when it is.
//   - Back never loses anything: keep every answer in ONE useForm above the wizard.
//   - Enter inside a field moves on; it never submits a half-finished form.
//   - A server-side error sends the person back to the earliest step that owns an errored field.
//   - The final action is a different, green button that names its outcome, and it is a fresh element
//     (distinct `key`) so the click that reached the last step cannot also submit the form.

export function useWizard({ steps, errors = {}, onSubmit }) {
    const [index, setIndex] = useState(0);
    const step = steps[index];
    const isLast = index === steps.length - 1;
    const canNext = step.isValid ? step.isValid() : true;

    useEffect(() => {
        const errored = Object.keys(errors);
        if (errored.length === 0) return;
        const earliest = steps.findIndex((s) => (s.fields ?? []).some((f) => errored.includes(f)));
        if (earliest !== -1) setIndex(earliest);
        // steps is rebuilt every render; only a change in the errors should re-run this
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [errors]);

    const next = () => {
        if (canNext && !isLast) setIndex((i) => i + 1);
    };
    const back = () => setIndex((i) => Math.max(0, i - 1));
    const goTo = (i) => setIndex(Math.min(Math.max(0, i), steps.length - 1));
    const reset = () => setIndex(0);

    const handleSubmit = (e) => {
        e?.preventDefault();
        if (!isLast) {
            next(); // Enter in a field advances; it must never send half a form
            return;
        }
        if (canNext) onSubmit();
    };

    return { steps, index, step, isFirst: index === 0, isLast, canNext, next, back, goTo, reset, handleSubmit };
}

/** "Step 2 of 4" + a segmented bar + the current step's title and one-sentence explanation. */
export function WizardProgress({ steps, current }) {
    const { t } = useTranslation();
    const label = t('wizard.step_of', { current: current + 1, total: steps.length });

    return (
        <div className="mb-6" role="group" aria-label={label}>
            <p className="text-sm font-medium text-stone-600 dark:text-stone-400">{label}</p>
            <div className="mt-2 flex gap-1.5" aria-hidden="true">
                {steps.map((step, i) => (
                    <div key={step.key} className={`h-2.5 flex-1 rounded-full ${i <= current ? 'bg-brand-600' : 'bg-stone-200 dark:bg-stone-700'}`} />
                ))}
            </div>
            <h3 className="mt-4 text-xl font-semibold text-stone-900 dark:text-stone-100">{steps[current].label}</h3>
            {steps[current].description && <p className="mt-1 text-base text-stone-600 dark:text-stone-400">{steps[current].description}</p>}
        </div>
    );
}

/**
 * Back + Next (or the final action). On a phone they stack with the main action on top, under the thumb.
 * `whyBlocked` appears when Next is off, so a greyed-out button is never a mystery.
 */
export function WizardButtons({ onBack, onNext, nextLabel, nextDisabled = false, isFinal = false, finalLabel, processing = false, whyBlocked }) {
    const { t } = useTranslation();

    return (
        <div className="pt-2">
            <div className="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                {onBack ? (
                    <Button variant="secondary" onClick={onBack} disabled={processing}>{t('wizard.back')}</Button>
                ) : (
                    <span />
                )}
                {/* The distinct key is load-bearing: without it React reuses the same <button> when "Next"
                    (type=button) becomes the final action (type=submit), and the very click that moved to
                    the last step also submits the form -- a payment sent without ever seeing the review. */}
                <Button
                    key={isFinal ? 'wizard-final' : 'wizard-next'}
                    variant={isFinal ? 'success' : 'primary'}
                    type={isFinal ? 'submit' : 'button'}
                    onClick={isFinal ? undefined : onNext}
                    disabled={nextDisabled}
                    busy={processing}
                    busyLabel={t('wizard.sending')}
                    className="sm:min-w-[180px]"
                >
                    {isFinal ? finalLabel : nextLabel ?? t('wizard.next')}
                </Button>
            </div>
            {nextDisabled && whyBlocked && <p className="mt-3 text-sm text-stone-600 dark:text-stone-400 sm:text-right">{whyBlocked}</p>}
        </div>
    );
}

/** Progress + the current step's content + the buttons, inside the <form>. */
export function WizardShell({ wizard, finalLabel, processing = false, children, className = '' }) {
    const { steps, index, isFirst, isLast, canNext, step } = wizard;

    return (
        <form onSubmit={wizard.handleSubmit} noValidate className={`space-y-5 ${className}`}>
            <WizardProgress steps={steps} current={index} />
            {children}
            <WizardButtons
                onBack={isFirst ? null : wizard.back}
                onNext={wizard.next}
                nextDisabled={!canNext}
                whyBlocked={step.whyBlocked}
                isFinal={isLast}
                finalLabel={finalLabel}
                processing={processing}
            />
        </form>
    );
}

/**
 * The final review: every answer, in words, with a "Change" shortcut back to the step that owns it.
 * rows: [{ label, value, step? }]
 */
export function ReviewList({ rows, onEdit }) {
    const { t } = useTranslation();

    return (
        <dl className="divide-y divide-stone-200 rounded-xl border-2 border-stone-200 text-base dark:divide-stone-700 dark:border-stone-700">
            {rows.map((row) => (
                <div key={row.label} className="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                    <dt className="text-stone-600 dark:text-stone-400">{row.label}</dt>
                    <dd className="flex items-center gap-3 sm:justify-end">
                        <span className="break-words font-semibold text-stone-900 dark:text-stone-100">{row.value}</span>
                        {onEdit && row.step !== undefined && (
                            <Button size="md" variant="quiet" onClick={() => onEdit(row.step)}>{t('wizard.change')}</Button>
                        )}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
