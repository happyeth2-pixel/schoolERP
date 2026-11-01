import { useState } from 'react';

// Shows photos/GIFs already attached to a message/announcement/complaint. Tapping one opens it full-size
// in a simple lightbox -- no library, just a fixed overlay, since this is only ever a handful of images.

export default function AttachmentGrid({ images = [] }) {
    const [open, setOpen] = useState(null);

    if (images.length === 0) return null;

    return (
        <>
            <div className="mt-2 flex flex-wrap gap-2" data-testid="attachment-grid">
                {images.map((src, i) => (
                    <button key={i} type="button" onClick={() => setOpen(src)} className="block h-24 w-24 overflow-hidden rounded-lg border border-stone-200 dark:border-stone-700">
                        <img src={src} alt="" className="h-full w-full object-cover" loading="lazy" />
                    </button>
                ))}
            </div>
            {open && (
                <div
                    role="dialog"
                    aria-modal="true"
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
                    onClick={() => setOpen(null)}
                    data-testid="attachment-lightbox"
                >
                    <img src={open} alt="" className="max-h-full max-w-full rounded-lg object-contain" />
                </div>
            )}
        </>
    );
}
