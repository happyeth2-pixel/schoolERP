// A flat stat-tile card with a thin, meaningful colour accent on its leading edge -- NOT a tilting /
// glowing card (that earlier effect was removed: a dashboard number that rocks under the pointer is a
// decoration, not information). The accent itself still carries real meaning in most of its call sites
// (green/amber/red = good/warning/bad, read from an actual rate -- see Dashboard.jsx's glowFor()), so it
// stays, just as a 4px border instead of a diffuse gradient wash across the whole tile. The name is kept
// to avoid touching every import; what it renders no longer has anything "3D" about it.
const ACCENT = {
    green: 'border-l-emerald-500',
    amber: 'border-l-amber-500',
    red: 'border-l-red-500',
    blue: 'border-l-sky-500',
    indigo: 'border-l-brand-600',
    purple: 'border-l-violet-500',
    gray: 'border-l-stone-300 dark:border-l-stone-600',
};

export default function Card3D({ children, glow = 'gray', className = '', as: Tag = 'div', ...props }) {
    return (
        <Tag
            {...props}
            className={`rounded-xl border border-stone-200 border-l-4 bg-white dark:border-stone-700 ${ACCENT[glow] ?? ACCENT.gray} dark:bg-stone-800 ${className}`}
        >
            {children}
        </Tag>
    );
}
