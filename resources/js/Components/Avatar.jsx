import { UserCircleIcon } from '@heroicons/react/24/outline';
import { useState } from 'react';

const COLORS = ['bg-brand-500', 'bg-emerald-500', 'bg-amber-500', 'bg-rose-500', 'bg-sky-500', 'bg-violet-500', 'bg-teal-500'];

/** A short, stable pick from the name itself, so the same person always gets the same color. */
function colorFor(name) {
    const s = name || '';
    let hash = 0;
    for (let i = 0; i < s.length; i++) hash = (hash * 31 + s.charCodeAt(i)) >>> 0;
    return COLORS[hash % COLORS.length];
}

function initialsOf(name) {
    const parts = (name || '').trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return '';

    return ((parts[0][0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] ?? '' : '')).toUpperCase();
}

/**
 * One photo-or-fallback avatar, used everywhere a person's picture appears (the topbar, the profile page, a
 * student's own profile, the student desk, the ID card). `src` may be a real URL, missing, or (rarely) pointing
 * at a file that no longer exists on disk -- the `onError` handler below is what tells the three apart: a
 * broken image never shows the browser's broken-image icon, it falls back exactly like a student/user with no
 * photo at all. `fallback="initials"` (people: staff/parents/the signed-in user) shows a plain colored circle
 * with their initials; `fallback="icon"` (students, where a blank silhouette already reads clearly) shows the
 * same person-outline icon used everywhere else in the app.
 */
export default function Avatar({ src, name = '', size = 32, shape = 'circle', fallback = 'icon', className = '' }) {
    const [broken, setBroken] = useState(false);
    const shapeClass = shape === 'circle' ? 'rounded-full' : 'rounded-lg';
    const style = { width: size, height: size };
    const showPhoto = src && !broken;

    if (showPhoto) {
        return (
            <img
                src={src}
                alt={name ? `${name}'s photo` : ''}
                style={style}
                className={`${shapeClass} shrink-0 object-cover ${className}`}
                loading="lazy"
                onError={() => setBroken(true)}
            />
        );
    }

    if (fallback === 'initials' && initialsOf(name)) {
        return (
            <span
                aria-hidden="true"
                style={style}
                className={`${shapeClass} ${colorFor(name)} flex shrink-0 items-center justify-center font-semibold text-white ${className}`}
            >
                <span style={{ fontSize: Math.max(10, size * 0.4) }}>{initialsOf(name)}</span>
            </span>
        );
    }

    return <UserCircleIcon aria-hidden="true" style={style} className={`shrink-0 text-stone-300 dark:text-stone-600 ${className}`} />;
}
