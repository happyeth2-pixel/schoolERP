import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';

/** "Grade 1 A — 3 of 30 places left" / "— FULL", for a section option. */
export const seatLabel = (s) =>
    s.capacity == null ? s.label : `${s.label} — ${s.full ? 'FULL' : `${s.seats_left} of ${s.capacity} places left`}`;

/**
 * Shown under a section choice when that section cannot take everyone being placed:
 * people who may override capacity can tick the box and give a reason (recorded in the
 * audit log); everyone else is told to ask an administrator.
 */
export default function CapacityOverride({ section, needed = 1, canOverride, checked, reason, onChecked, onReason, error }) {
    if (!section || section.capacity == null || (section.seats_left ?? Infinity) >= needed) {
        return null;
    }

    const short = needed > 1 ? `${section.seats_left} place(s) left for ${needed} students` : 'is full';

    return (
        <div className="mt-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-200" role="alert">
            <p>{section.label} {needed > 1 ? `has only ${short}.` : `${short} (${section.capacity} places).`}</p>
            {canOverride ? (
                <div className="mt-2 space-y-2">
                    <label className="flex items-center gap-2">
                        <input type="checkbox" checked={!!checked} onChange={(e) => onChecked(e.target.checked)} className="rounded border-gray-300 text-indigo-600" />
                        <span>Override capacity</span>
                    </label>
                    {checked && (
                        <div>
                            <InputLabel htmlFor="override_reason" value="Reason (recorded in the audit log)" />
                            <TextInput id="override_reason" className="mt-1 block w-full" value={reason ?? ''} onChange={(e) => onReason(e.target.value)} />
                        </div>
                    )}
                </div>
            ) : (
                <p className="mt-1 text-xs">Ask an owner or administrator if this student must be placed anyway.</p>
            )}
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
        </div>
    );
}
