import { motion } from 'framer-motion';

// The Dashboard home's glass card for a chart/meter (attendance donut, fee donut, staff-attendance
// meter, ...) -- same frosted treatment as GlassStatTile, same reason it's its own component instead
// of touching Card3D: this is Dashboard.jsx only.
export default function GlassPanel({ children, title, className = '', index = 0 }) {
    return (
        <motion.div
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.4, delay: Math.min(index, 8) * 0.05, ease: 'easeOut' }}
            className={`dash-glass rounded-2xl p-5 ring-1 ring-black/5 dark:ring-white/10 ${className}`}
        >
            {title && <h3 className="mb-3 text-sm font-semibold text-stone-600 dark:text-stone-300">{title}</h3>}
            {children}
        </motion.div>
    );
}
