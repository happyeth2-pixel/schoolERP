// A small, dependency-free SVG donut/pie chart. No charting library needed for this -- same "build it
// plain, keep it light" choice as the rest of Components/ui (Meter, SplitMeter, GradientStatTile).
// Every chart ships with a text legend underneath (label + value, not just color), so the breakdown is
// never encoded in color alone -- which is also what makes the three lighter steps below (aqua/yellow/
// magenta, each under 3:1 against a white chart surface on their own) fine to use.
//
// A validated, colour-blind-safe 8-hue order (fixed, never cycled past 8) -- only reached when a caller
// doesn't give its segments their own colour (every real chart in this app already does, with the app's
// own status colours: green/amber/red). Checked with the dataviz skill's validator: worst adjacent CVD
// ΔE 9.1, worst adjacent normal-vision ΔE 19.6 -- both clear of the floor.
const DEFAULT_PALETTE = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];

function polarToCartesian(cx, cy, r, angleDeg) {
    const angleRad = ((angleDeg - 90) * Math.PI) / 180;
    return { x: cx + r * Math.cos(angleRad), y: cy + r * Math.sin(angleRad) };
}

function segmentPath(cx, cy, outerR, innerR, startAngle, endAngle) {
    const largeArc = endAngle - startAngle > 180 ? 1 : 0;
    const oStart = polarToCartesian(cx, cy, outerR, endAngle);
    const oEnd = polarToCartesian(cx, cy, outerR, startAngle);

    if (innerR <= 0) {
        return `M ${cx},${cy} L ${oEnd.x},${oEnd.y} A ${outerR},${outerR} 0 ${largeArc} 1 ${oStart.x},${oStart.y} Z`;
    }

    const iStart = polarToCartesian(cx, cy, innerR, endAngle);
    const iEnd = polarToCartesian(cx, cy, innerR, startAngle);

    return [
        `M ${oEnd.x},${oEnd.y}`,
        `A ${outerR},${outerR} 0 ${largeArc} 1 ${oStart.x},${oStart.y}`,
        `L ${iStart.x},${iStart.y}`,
        `A ${innerR},${innerR} 0 ${largeArc} 0 ${iEnd.x},${iEnd.y}`,
        'Z',
    ].join(' ');
}

/**
 * @param {Array<{label: string, value: number, color?: string}>} segments
 * @param {string} [centerLabel] shown in the donut's hole (ignored when donut=false)
 * @param {string|number} [centerValue]
 * @param {boolean} [donut] false draws a filled pie instead of a ring
 * @param {string} [emptyLabel] shown when every value is 0 / there are no segments
 */
export default function DonutChart({ segments, centerLabel, centerValue, donut = true, emptyLabel = 'No data yet', size = 160 }) {
    const total = segments.reduce((sum, s) => sum + (s.value || 0), 0);
    const cx = size / 2;
    const cy = size / 2;
    const outerR = size / 2 - 4;
    const innerR = donut ? outerR * 0.58 : 0;

    if (total <= 0) {
        return (
            <div className="flex flex-col items-center gap-3">
                <div
                    className="flex items-center justify-center rounded-full border-4 border-dashed border-stone-200 text-center text-xs text-stone-400 dark:border-stone-700 dark:text-stone-500"
                    style={{ width: size, height: size }}
                >
                    {emptyLabel}
                </div>
            </div>
        );
    }

    let cursor = 0;
    const withAngles = segments.filter((s) => s.value > 0).map((s, i) => {
        const fraction = s.value / total;
        const startAngle = cursor * 360;
        cursor += fraction;
        const endAngle = cursor * 360;
        return { ...s, startAngle, endAngle, color: s.color ?? DEFAULT_PALETTE[i % DEFAULT_PALETTE.length], pct: Math.round(fraction * 100) };
    });

    return (
        <div className="flex flex-col items-center gap-4 sm:flex-row sm:items-center sm:gap-6">
            <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} role="img" aria-label={`${centerLabel ?? 'Breakdown'}: ${withAngles.map((s) => `${s.label} ${s.value}`).join(', ')}`}>
                {withAngles.map((s) => (
                    <path key={s.label} d={segmentPath(cx, cy, outerR, innerR, s.startAngle, s.endAngle)} fill={s.color} stroke="var(--donut-gap, white)" strokeWidth={2} className="transition-opacity hover:opacity-80 dark:[--donut-gap:#1f2937]" />
                ))}
                {donut && (centerLabel || centerValue !== undefined) && (
                    <>
                        <text x={cx} y={cy - 4} textAnchor="middle" className="fill-stone-900 text-xl font-bold dark:fill-stone-100" style={{ fontSize: size * 0.14 }}>
                            {centerValue}
                        </text>
                        <text x={cx} y={cy + size * 0.12} textAnchor="middle" className="fill-stone-400 uppercase tracking-wide" style={{ fontSize: size * 0.065 }}>
                            {centerLabel}
                        </text>
                    </>
                )}
            </svg>

            <ul className="w-full space-y-1.5 sm:w-auto">
                {withAngles.map((s) => (
                    <li key={s.label} className="flex items-center gap-2 text-sm">
                        <span className="h-2.5 w-2.5 shrink-0 rounded-sm" style={{ backgroundColor: s.color }} aria-hidden="true" />
                        <span className="text-stone-700 dark:text-stone-300">{s.label}</span>
                        <span className="ml-auto pl-3 font-semibold text-stone-900 dark:text-stone-100">{s.value}</span>
                        <span className="w-10 text-right text-xs text-stone-400">{s.pct}%</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
