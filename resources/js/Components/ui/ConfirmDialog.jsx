import Modal from '@/Components/Modal';
import { DialogTitle } from '@headlessui/react';
import { ExclamationTriangleIcon, QuestionMarkCircleIcon } from '@heroicons/react/24/solid';
import { useCallback, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Button from './Button';
import { TONE } from './tokens';

// The replacement for window.confirm() (16 places use it today). The browser's box says "OK / Cancel",
// cannot be styled, cannot be translated by us, and gives no clue what "OK" will do. This one:
//   - has a title that says what is about to happen ("Delete Student?"),
//   - lists what it affects / what is kept in plain words,
//   - has a confirm button that NAMES THE OUTCOME ("Delete Student"), never "OK" or "Yes",
//   - puts Cancel first, so for a dangerous action the safe choice is what has keyboard focus.

export default function ConfirmDialog({ open, title, children, confirmLabel, cancelLabel, tone = 'danger', busy = false, onConfirm, onCancel }) {
    const { t } = useTranslation();
    const Icon = tone === 'danger' ? ExclamationTriangleIcon : QuestionMarkCircleIcon;

    return (
        <Modal show={open} maxWidth="md" onClose={busy ? () => {} : onCancel} closeable={!busy}>
            <div className="p-6">
                <div className="flex items-start gap-4">
                    <Icon className={`h-8 w-8 shrink-0 ${tone === 'danger' ? TONE.danger.icon : TONE.info.icon}`} aria-hidden="true" />
                    <div className="min-w-0">
                        <DialogTitle as="h2" className="text-xl font-semibold text-stone-900 dark:text-stone-100">{title}</DialogTitle>
                        {children && <div className="mt-2 space-y-2 text-base text-stone-700 dark:text-stone-300">{children}</div>}
                    </div>
                </div>
                {/* DOM order = Cancel, then the action; on a phone the action is shown on top (flex-col-reverse) */}
                <div className="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    {/* data-autofocus: Headless UI puts initial focus on it -- the safe choice, never the destructive one */}
                    <Button variant="secondary" onClick={onCancel} disabled={busy} data-autofocus>{cancelLabel ?? t('common.cancel')}</Button>
                    <Button variant={tone === 'danger' ? 'danger' : 'primary'} onClick={onConfirm} busy={busy}>{confirmLabel}</Button>
                </div>
            </div>
        </Modal>
    );
}

/**
 * const [ask, confirmDialog] = useConfirm();
 * ...
 * if (await ask({ title: 'Delete Student?', body: <p>…</p>, confirmLabel: 'Delete Student' })) { router.delete(...) }
 * ...
 * return (<>{page}{confirmDialog}</>);
 * A drop-in for `if (confirm('…'))`: the answer is a promise of true/false.
 */
export function useConfirm() {
    const [pending, setPending] = useState(null);
    const last = useRef({});

    const ask = useCallback(
        (options) =>
            new Promise((resolve) => {
                last.current = options;
                setPending({ resolve });
            }),
        []
    );

    const settle = (answer) => {
        pending?.resolve(answer);
        setPending(null);
    };

    const o = last.current;
    const dialog = (
        <ConfirmDialog open={pending !== null} title={o.title} confirmLabel={o.confirmLabel} cancelLabel={o.cancelLabel} tone={o.tone} onConfirm={() => settle(true)} onCancel={() => settle(false)}>
            {o.body}
        </ConfirmDialog>
    );

    return [ask, dialog];
}
