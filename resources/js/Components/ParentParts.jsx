import { STATUSES, statusLabelKey } from '@/Components/AttendanceParts';
import { CheckIcon } from '@heroicons/react/24/solid';
import { ClockIcon } from '@heroicons/react/24/outline';
import { useTranslation } from 'react-i18next';

// Small pieces the parent's pages share, so a parent who has learned one screen has learned them all.

const AVATARS = ['bg-brand-600', 'bg-sky-700', 'bg-emerald-700', 'bg-amber-700', 'bg-rose-700'];

export function Avatar({ name, index = 0, size = 'h-12 w-12 text-xl' }) {
    return (
        <span className={`flex shrink-0 items-center justify-center rounded-full font-bold text-white ${AVATARS[index % AVATARS.length]} ${size}`} aria-hidden="true">
            {name?.trim()?.[0]?.toUpperCase() ?? '?'}
        </span>
    );
}

/**
 * "Whose information do you want to see?" With one child it just names them; with several it is a row of big
 * buttons, the chosen child filled and ticked, so it is always clear whose page this is and how to change it.
 * `children` is [{ id, name, first_name?, class? }]; `onChange(id)` is the caller's business (switch in place, or navigate).
 */
export function ChildSwitcher({ children, value, onChange }) {
    const { t } = useTranslation();
    if (children.length === 0) return null;

    if (children.length === 1) {
        const c = children[0];
        return (
            <div className="flex items-center gap-3" data-testid="child-single">
                <Avatar name={c.name} />
                <div className="min-w-0">
                    <p className="truncate text-xl font-bold text-stone-900 dark:text-stone-100">{c.name}</p>
                    {c.class && <p className="text-base font-bold text-brand-700 dark:text-brand-400">{c.class}</p>}
                </div>
            </div>
        );
    }

    return (
        <div data-testid="child-switcher">
            <p className="mb-2 text-lg font-semibold text-stone-900 dark:text-stone-100">{t('parent.switch_label')}</p>
            <div role="radiogroup" aria-label={t('parent.switch_label')} className="grid gap-3 sm:grid-cols-2">
                {children.map((c, i) => {
                    const selected = c.id === value;
                    return (
                        <button
                            key={c.id}
                            type="button"
                            role="radio"
                            aria-checked={selected}
                            onClick={() => onChange(c.id)}
                            className={`flex min-h-[72px] items-center gap-3 rounded-2xl border-2 px-4 py-3 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 ${selected ? 'border-brand-600 bg-brand-600 text-white' : 'border-stone-300 bg-white text-stone-900 hover:border-brand-400 dark:border-stone-600 dark:bg-stone-800 dark:text-stone-100'}`}
                        >
                            <Avatar name={c.name} index={i} size="h-12 w-12 text-xl ring-2 ring-white/70" />
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-lg font-bold">{c.first_name ?? c.name}</span>
                                {c.class && <span className={`block text-sm font-bold ${selected ? 'text-white' : 'text-brand-700 dark:text-brand-400'}`}>{c.class}</span>}
                            </span>
                            {selected && <CheckIcon className="h-6 w-6 shrink-0" aria-hidden="true" />}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

/** A day's attendance as a big, unmistakable state: icon + word + colour ("Not recorded yet" when there is none). */
export function TodayStatus({ status }) {
    const { t } = useTranslation();
    const s = STATUSES.find((x) => x.value === status);

    if (!s) {
        return (
            <div className="flex items-center gap-3 rounded-2xl border-2 border-dashed border-stone-300 px-5 py-4 dark:border-stone-600" data-testid="today-status" data-status="none">
                <ClockIcon className="h-9 w-9 text-stone-600 dark:text-stone-300" aria-hidden="true" />
                <div>
                    <p className="text-2xl font-bold text-stone-900 dark:text-stone-100">{t('parent.today_none')}</p>
                    <p className="text-base text-stone-700 dark:text-stone-300">{t('parent.today_none_hint')}</p>
                </div>
            </div>
        );
    }

    return (
        <div className={`flex items-center gap-3 rounded-2xl px-5 py-4 ${s.chip}`} data-testid="today-status" data-status={status}>
            <s.icon className="h-9 w-9 shrink-0" aria-hidden="true" />
            <p className="text-2xl font-bold">{t(statusLabelKey(status))}</p>
        </div>
    );
}

/** One recorded day as a small chip: weekday, date, and the status in words. */
export function DayChip({ date, status, label }) {
    const { t } = useTranslation();
    const s = STATUSES.find((x) => x.value === status);

    return (
        <li className={`flex min-w-[84px] flex-col items-center rounded-xl px-2 py-2 text-center text-sm font-semibold ${s?.chip ?? 'bg-stone-100 text-stone-900'}`} data-testid="day-chip" data-status={status}>
            <span className="text-xs font-medium opacity-90">{label}</span>
            {s && <s.icon className="my-0.5 h-5 w-5" aria-hidden="true" />}
            <span>{s ? t(statusLabelKey(status)) : status}</span>
        </li>
    );
}

export const money = (n) => Number(n ?? 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
