const SEVERITY_COLORS = {
    good: { fill: 'bg-green-600', track: 'bg-green-100 dark:bg-green-900/30' },
    warning: { fill: 'bg-amber-500', track: 'bg-amber-100 dark:bg-amber-900/30' },
    danger: { fill: 'bg-red-600', track: 'bg-red-100 dark:bg-red-900/30' },
    neutral: { fill: 'bg-brand-600', track: 'bg-brand-100 dark:bg-brand-900/30' },
};

function severityFor(value, thresholds) {
    if (value >= thresholds.good) return 'good';
    if (value >= thresholds.warning) return 'warning';
    return 'danger';
}

/**
 * A single ratio against a limit (an attendance rate, a fee collection
 * rate) reads far faster as a filled bar than as a lone "72%" — the
 * fill color itself carries whether that number is fine or a problem,
 * so nobody has to already know the target to judge it.
 */
export default function Meter({ label, value, thresholds = { good: 90, warning: 75 }, sublabel, emptyLabel = 'No data yet', bare = false }) {
    const hasValue = value !== null && value !== undefined;
    const pct = hasValue ? Math.max(0, Math.min(100, value)) : 0;
    const severity = hasValue ? severityFor(value, thresholds) : 'neutral';
    const colors = SEVERITY_COLORS[severity];

    return (
        <div className={bare ? '' : 'rounded-lg border border-stone-200 bg-white p-5 dark:border-stone-700 dark:bg-stone-800'}>
            <div className="flex items-baseline justify-between gap-2">
                <p className="text-sm text-stone-500 dark:text-stone-400">{label}</p>
                <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{hasValue ? `${value}%` : '—'}</p>
            </div>
            <div
                className={`mt-3 h-3 w-full overflow-hidden rounded-full ${colors.track}`}
                role="meter"
                aria-label={label}
                aria-valuenow={hasValue ? value : 0}
                aria-valuemin={0}
                aria-valuemax={100}
                title={hasValue ? `${value}%` : emptyLabel}
            >
                <div className={`h-full rounded-full ${colors.fill} transition-all duration-700 ease-out`} style={{ width: `${pct}%` }} />
            </div>
            <p className="mt-1.5 text-xs text-stone-400">{hasValue ? (sublabel ?? ' ') : emptyLabel}</p>
        </div>
    );
}
