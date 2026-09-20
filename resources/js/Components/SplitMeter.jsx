/**
 * Paid vs. remaining on one invoice (or across a whole school) is a
 * part-to-whole story, not two separate numbers — a two-segment bar
 * shows the balance at a glance, the way the number pair alone can't.
 */
export default function SplitMeter({ label, paid, total, emptyLabel = 'No invoice yet' }) {
    const hasValue = total !== null && total !== undefined && total > 0;
    const balance = hasValue ? Math.max(0, total - paid) : 0;
    const paidPct = hasValue ? Math.max(0, Math.min(100, (paid / total) * 100)) : 0;
    const balancePct = hasValue ? Math.max(0, 100 - paidPct) : 0;

    return (
        <div className="rounded-lg border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
            <p className="text-sm text-gray-500 dark:text-gray-400">{label}</p>
            {hasValue ? (
                <>
                    <div className="mt-3 flex h-3 w-full gap-0.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                        {paidPct > 0 && (
                            <div
                                className="h-full rounded-full bg-green-600 transition-all duration-700 ease-out"
                                style={{ width: `${paidPct}%` }}
                                title={`${Number(paid).toLocaleString()} ETB paid`}
                            />
                        )}
                        {balancePct > 0 && (
                            <div
                                className="h-full rounded-full bg-gray-300 transition-all duration-700 ease-out dark:bg-gray-600"
                                style={{ width: `${balancePct}%` }}
                                title={`${Number(balance).toLocaleString()} ETB remaining`}
                            />
                        )}
                    </div>
                    <div className="mt-1.5 flex items-center justify-between text-xs text-gray-400">
                        <span className="flex items-center gap-1">
                            <span className="inline-block h-2 w-2 rounded-full bg-green-600" />
                            {Number(paid).toLocaleString()} ETB paid
                        </span>
                        <span>{Number(balance).toLocaleString()} ETB left</span>
                    </div>
                </>
            ) : (
                <p className="mt-3 text-xs text-gray-400">{emptyLabel}</p>
            )}
        </div>
    );
}
