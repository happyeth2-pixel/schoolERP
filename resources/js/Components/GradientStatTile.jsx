import Card3D from '@/Components/Card3D';

const GRADIENT = {
    indigo: 'from-indigo-500 to-indigo-700',
    purple: 'from-violet-500 to-purple-700',
    blue: 'from-sky-500 to-blue-700',
    green: 'from-emerald-500 to-green-700',
    amber: 'from-amber-400 to-orange-600',
    red: 'from-rose-500 to-red-700',
};

/**
 * A single count reads as a stat tile, not a chart — but a flat white
 * box with a number is easy to skim past. A colorful icon badge gives
 * each number a visual anchor a parent or first-time user can recognize
 * at a glance, without turning a count into something it isn't.
 */
export default function GradientStatTile({ icon: Icon, label, value, color = 'indigo' }) {
    return (
        <Card3D glow={color} className="p-5">
            <div className="flex items-center gap-4">
                <div className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br ${GRADIENT[color]} shadow-lg`}>
                    <Icon className="h-6 w-6 text-white" />
                </div>
                <div>
                    <p className="text-sm text-gray-500 dark:text-gray-400">{label}</p>
                    <p className="text-2xl font-bold text-gray-900 dark:text-gray-100">{value}</p>
                </div>
            </div>
        </Card3D>
    );
}
