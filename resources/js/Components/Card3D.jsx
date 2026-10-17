import { useRef } from 'react';

const GLOW = {
    green: 'from-emerald-400/25',
    amber: 'from-amber-400/25',
    red: 'from-rose-400/25',
    indigo: 'from-indigo-400/25',
    purple: 'from-violet-400/25',
    blue: 'from-sky-400/25',
    gray: 'from-gray-400/20',
};

export default function Card3D({ children, glow = 'indigo', className = '', as: Tag = 'div', ...props }) {
    const ref = useRef(null);

    const onMouseMove = (e) => {
        const el = ref.current;
        if (!el) return;
        const rect = el.getBoundingClientRect();
        const x = (e.clientX - rect.left) / rect.width - 0.5;
        const y = (e.clientY - rect.top) / rect.height - 0.5;
        el.style.transform = `perspective(800px) rotateY(${x * 8}deg) rotateX(${-y * 8}deg) translateZ(6px)`;
    };

    const onMouseLeave = () => {
        const el = ref.current;
        if (!el) return;
        el.style.transform = 'perspective(800px) rotateY(0deg) rotateX(0deg) translateZ(0px)';
    };

    return (
        <Tag
            ref={ref}
            onMouseMove={onMouseMove}
            onMouseLeave={onMouseLeave}
            className={`group relative overflow-hidden rounded-2xl border border-gray-200/70 bg-white shadow-md transition-transform duration-150 ease-out will-change-transform dark:border-gray-700/70 dark:bg-gray-800 ${className}`}
            style={{ transformStyle: 'preserve-3d' }}
            {...props}
        >
            <div
                className={`pointer-events-none absolute inset-0 bg-gradient-to-br ${GLOW[glow] ?? GLOW.indigo} to-transparent opacity-0 transition-opacity duration-200 group-hover:opacity-100`}
            />
            <div className="relative">{children}</div>
        </Tag>
    );
}
