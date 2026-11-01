import { CheckIcon, ClipboardDocumentIcon, XMarkIcon } from '@heroicons/react/24/outline';
import { usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Shown once, right after an account is created (or a new invitation is made),
 * to the person who created it: the one-time "set your password" link, so it can
 * be handed over in person or by WhatsApp/SMS when email isn't available.
 */
export default function InvitationNotice() {
    const { flash } = usePage().props;
    // the "Add a staff member" page shows the link itself, in its own confirmation
    const invitation = usePage().component === 'Staff/Wizard' ? null : flash?.invitation;
    const [dismissed, setDismissed] = useState(null);
    const [copied, setCopied] = useState(false);
    const warning = flash?.warning;
    const { errors } = usePage().props;
    const blocked = errors?.delete;

    if (!invitation || dismissed === invitation.url) {
        return warning || blocked ? <WarningBanner text={blocked || warning} /> : null;
    }

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(invitation.url);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            /* the link is selectable below as a fallback */
        }
    };

    return (
        <div className="mx-auto mt-4 max-w-4xl px-4 sm:px-6 lg:px-8">
            {(warning || blocked) && <WarningBanner text={blocked || warning} inline />}
            <div className="rounded-lg border border-brand-200 bg-brand-50 p-4 text-sm dark:border-brand-800 dark:bg-brand-900/20">
                <div className="flex items-start justify-between gap-3">
                    <p className="font-semibold text-brand-900 dark:text-brand-200">
                        Invitation link for {invitation.name} ({invitation.email})
                    </p>
                    <button onClick={() => setDismissed(invitation.url)} className="text-brand-400 hover:text-brand-600" aria-label="Dismiss">
                        <XMarkIcon className="h-5 w-5" />
                    </button>
                </div>
                <p className="mt-1 text-brand-800 dark:text-brand-300">
                    {invitation.emailed
                        ? 'We also emailed this link to them. '
                        : 'Email delivery is not set up, so send this link to them yourself (in person, WhatsApp or SMS). '}
                    It works <strong>once</strong> and expires {invitation.expires_at}. You will not see it again — you can create a new one from the list.
                </p>
                <div className="mt-2 flex flex-wrap items-center gap-2">
                    <input readOnly value={invitation.url} onFocus={(e) => e.target.select()} className="min-w-0 flex-1 rounded-md border-brand-200 bg-white text-xs text-stone-700 dark:border-brand-800 dark:bg-stone-900 dark:text-stone-200" />
                    <button onClick={copy} className="inline-flex items-center gap-1.5 rounded-md bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700">
                        {copied ? <CheckIcon className="h-4 w-4" /> : <ClipboardDocumentIcon className="h-4 w-4" />}
                        {copied ? 'Copied' : 'Copy link'}
                    </button>
                </div>
            </div>
        </div>
    );
}

function WarningBanner({ text, inline = false }) {
    const box = <div role="alert" className="mb-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-200">{text}</div>;
    return inline ? box : <div className="mx-auto mt-4 max-w-4xl px-4 sm:px-6 lg:px-8">{box}</div>;
}
