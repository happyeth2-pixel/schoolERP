import { Alert, Button } from '@/Components/ui';
import { PhotoIcon, XMarkIcon } from '@heroicons/react/24/outline';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

// Attaching a few photos or GIFs to a message/announcement/complaint -- never video (see
// AttachmentUpload::MAX_PER_POST and SafeUpload::storePublicAttachment()). A jpg/png is shrunk in the
// browser first, the same idea StudentPhotoField already uses, so a large phone photo doesn't sit uploading
// on a slow connection; a GIF is sent exactly as chosen, since shrinking it in a <canvas> would collapse its
// animation to a single frame.

const MAX_SIDE = 1280;
const JPEG_QUALITY = 0.82;

async function shrinkIfNeeded(file) {
    if (file.type === 'image/gif') return file;

    const bitmap = await (window.createImageBitmap ? createImageBitmap(file, { imageOrientation: 'from-image' }) : loadImg(file));
    const w = bitmap.width ?? bitmap.naturalWidth;
    const h = bitmap.height ?? bitmap.naturalHeight;
    const scale = Math.min(1, MAX_SIDE / Math.max(w, h));
    if (scale === 1 && file.size < 1.5 * 1024 * 1024) return file; // already small enough

    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(w * scale));
    canvas.height = Math.max(1, Math.round(h * scale));
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);

    const isPng = file.type === 'image/png';
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, isPng ? 'image/png' : 'image/jpeg', JPEG_QUALITY));

    return blob ? new File([blob], file.name, { type: isPng ? 'image/png' : 'image/jpeg' }) : file;
}

function loadImg(file) {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
        img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('unreadable')); };
        img.src = url;
    });
}

export default function AttachmentPicker({ value = [], onChange, max = 4, error, busy = false }) {
    const { t } = useTranslation();
    const fileRef = useRef(null);
    const [previews, setPreviews] = useState([]);
    const [working, setWorking] = useState(false);
    const [notice, setNotice] = useState(null);

    useEffect(() => {
        const urls = value.map((f) => URL.createObjectURL(f));
        setPreviews(urls);
        return () => urls.forEach((u) => URL.revokeObjectURL(u));
    }, [value]);

    const pick = async (e) => {
        const chosen = Array.from(e.target.files ?? []);
        e.target.value = '';
        if (chosen.length === 0) return;
        setNotice(null);

        const room = max - value.length;
        if (room <= 0) {
            setNotice(t('attachments.limit_reached', { max }));
            return;
        }
        const accepted = chosen.slice(0, room);
        if (chosen.length > room) setNotice(t('attachments.limit_reached', { max }));

        setWorking(true);
        try {
            const ready = [];
            for (const file of accepted) {
                if (!['image/jpeg', 'image/png', 'image/gif'].includes(file.type)) {
                    setNotice(t('attachments.wrong_type'));
                    continue;
                }
                ready.push(await shrinkIfNeeded(file));
            }
            onChange([...value, ...ready]);
        } catch {
            setNotice(t('attachments.unreadable'));
        } finally {
            setWorking(false);
        }
    };

    const remove = (i) => onChange(value.filter((_, idx) => idx !== i));

    return (
        <div className="space-y-3" data-testid="attachment-picker">
            {previews.length > 0 && (
                <div className="flex flex-wrap gap-2" data-testid="attachment-previews">
                    {previews.map((url, i) => (
                        <div key={i} className="relative h-20 w-20 overflow-hidden rounded-lg border-2 border-stone-200 dark:border-stone-700">
                            <img src={url} alt="" className="h-full w-full object-cover" />
                            <button
                                type="button"
                                onClick={() => remove(i)}
                                disabled={busy}
                                aria-label={t('attachments.remove')}
                                className="absolute right-0.5 top-0.5 rounded-full bg-black/60 p-1 text-white hover:bg-black/80"
                            >
                                <XMarkIcon className="h-3.5 w-3.5" aria-hidden="true" />
                            </button>
                        </div>
                    ))}
                </div>
            )}

            {value.length < max && (
                <Button type="button" variant="secondary" size="md" icon={PhotoIcon} onClick={() => fileRef.current?.click()} busy={working} disabled={busy}>
                    {t('attachments.add_button')}
                </Button>
            )}
            <p className="text-sm text-stone-600 dark:text-stone-400">{t('attachments.hint', { max })}</p>

            {notice && <Alert tone="warning">{notice}</Alert>}
            {error && <Alert tone="danger">{error}</Alert>}

            <input
                ref={fileRef}
                type="file"
                accept="image/jpeg,image/png,image/gif"
                multiple
                className="sr-only"
                tabIndex={-1}
                aria-hidden="true"
                onChange={pick}
                data-testid="attachment-file-input"
            />
        </div>
    );
}
