import Card3D from '@/Components/Card3D';

// A flat tint, not a gradient+shadow badge: the icon square is a single muted colour, the number carries
// the actual weight on the tile. `color` still groups stats by kind (and, in many call sites, by whether
// the number itself is good/warning/bad -- see Dashboard.jsx's glowFor()) -- it just no longer shines.
const TINT = {
    indigo: 'bg-brand-50 text-brand-700 dark:bg-brand-900/30 dark:text-brand-300',
    purple: 'bg-violet-50 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300',
    blue: 'bg-sky-50 text-sky-700 dark:bg-sky-900/30 dark:text-sky-300',
    green: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
    amber: 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
    red: 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300',
};

/** A single count reads as a stat, not a chart -- the icon tint gives it a visual anchor to scan for,
 *  without turning a number into decoration. */
export default function GradientStatTile({ icon: Icon, label, value, color = 'indigo' }) {
    return (
        <Card3D glow={color} className="p-5">
            <div className="flex items-center gap-4">
                <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-lg ${TINT[color] ?? TINT.indigo}`}>
                    <Icon className="h-6 w-6" />
                </div>
                <div className="min-w-0">
                    <p className="text-sm text-stone-500 dark:text-stone-400">{label}</p>
                    <p className="text-2xl font-bold tracking-tight text-stone-900 dark:text-stone-100">{value}</p>
                </div>
            </div>
        </Card3D>
    );
}
