// A small, dependency-free grouped (clustered) column chart -- same "build it plain, no charting
// library" choice as DonutChart.jsx. X axis = categories (e.g. years), Y axis = a shared count scale,
// one column per series per category. Follows the dataviz skill's mark spec: <=24px-thick columns,
// 4px-rounded caps square at the baseline, a 2px surface gap between touching columns, hairline
// recessive gridlines, a value label on every cap (few categories, so labeling all of them stays
// readable), and a legend below since this always draws >=2 series.

const BAR_MAX = 20;
const GAP = 2;

function niceMax(max) {
    if (max <= 0) return 4;
    const magnitude = Math.pow(10, Math.floor(Math.log10(max)));
    const normalized = max / magnitude;
    const step = normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10;
    return step * magnitude;
}

/**
 * @param {Array<Object>} data - e.g. [{ year: '2026', male: 10, female: 8 }, ...]
 * @param {Array<{key: string, label: string, color: string}>} series
 * @param {string} xKey - the field in each row used as the x-axis category label
 * @param {string} [emptyLabel]
 */
export default function GroupedBarChart({ data, series, xKey, emptyLabel = 'No data yet', width = 560, height = 220 }) {
    const hasAny = data.length > 0 && data.some((row) => series.some((s) => (row[s.key] || 0) > 0));

    if (!hasAny) {
        return (
            <div className="flex items-center justify-center rounded-xl border-4 border-dashed border-stone-200 text-center text-sm text-stone-400 dark:border-stone-700 dark:text-stone-500" style={{ height }}>
                {emptyLabel}
            </div>
        );
    }

    const marginLeft = 28;
    const marginBottom = 24;
    const marginTop = 12;
    const plotW = width - marginLeft - 12;
    const plotH = height - marginTop - marginBottom;

    const maxValue = niceMax(Math.max(...data.flatMap((row) => series.map((s) => row[s.key] || 0))));
    const ticks = [0, 0.25, 0.5, 0.75, 1].map((f) => Math.round(maxValue * f));

    const groupW = plotW / data.length;
    const barW = Math.min(BAR_MAX, (groupW - GAP * (series.length + 1)) / series.length);
    const clusterW = barW * series.length + GAP * (series.length - 1);

    const yFor = (v) => plotH - (v / maxValue) * plotH;

    return (
        <div>
            <svg width="100%" viewBox={`0 0 ${width} ${height}`} role="img" aria-label={`${series.map((s) => s.label).join(' vs ')} by ${xKey}`}>
                <g transform={`translate(${marginLeft},${marginTop})`}>
                    {ticks.map((tick) => (
                        <g key={tick}>
                            <line x1={0} x2={plotW} y1={yFor(tick)} y2={yFor(tick)} stroke="currentColor" strokeWidth={1} className="text-stone-200 dark:text-stone-700" />
                            <text x={-8} y={yFor(tick)} textAnchor="end" dominantBaseline="middle" className="fill-stone-400 text-[10px] dark:fill-stone-500">{tick}</text>
                        </g>
                    ))}

                    {data.map((row, i) => {
                        const groupX = i * groupW + (groupW - clusterW) / 2;

                        return (
                            <g key={row[xKey]}>
                                {series.map((s, si) => {
                                    const value = row[s.key] || 0;
                                    const barH = plotH - yFor(value);
                                    const barX = groupX + si * (barW + GAP);

                                    return (
                                        <g key={s.key}>
                                            <title>{`${row[xKey]} — ${s.label}: ${value}`}</title>
                                            <rect
                                                x={barX}
                                                y={yFor(value)}
                                                width={barW}
                                                height={Math.max(barH, 0)}
                                                rx={4}
                                                fill={s.color}
                                                className="transition-opacity hover:opacity-80"
                                            />
                                            {value > 0 && (
                                                <text x={barX + barW / 2} y={yFor(value) - 4} textAnchor="middle" className="fill-stone-600 text-[10px] font-semibold dark:fill-stone-300">
                                                    {value}
                                                </text>
                                            )}
                                        </g>
                                    );
                                })}
                                <text x={groupX + clusterW / 2} y={plotH + 18} textAnchor="middle" className="fill-stone-500 text-[11px] dark:fill-stone-400">
                                    {row[xKey]}
                                </text>
                            </g>
                        );
                    })}

                    <line x1={0} x2={plotW} y1={plotH} y2={plotH} stroke="currentColor" strokeWidth={1} className="text-stone-300 dark:text-stone-600" />
                </g>
            </svg>

            <ul className="mt-2 flex flex-wrap justify-center gap-4">
                {series.map((s) => (
                    <li key={s.key} className="flex items-center gap-2 text-sm">
                        <span className="h-2.5 w-2.5 shrink-0 rounded-sm" style={{ backgroundColor: s.color }} aria-hidden="true" />
                        <span className="text-stone-700 dark:text-stone-300">{s.label}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
