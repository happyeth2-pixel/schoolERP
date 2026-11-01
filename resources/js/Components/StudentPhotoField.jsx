import { Alert, Button } from '@/Components/ui';
import { ArrowPathIcon, CameraIcon, CheckIcon, PhotoIcon, TrashIcon, UserCircleIcon, XMarkIcon } from '@heroicons/react/24/outline';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

// The student's photo, taken or chosen by the STAFF member registering or editing them (the student uploads nothing
// and needs no account). Two ways in, neither mandatory:
//   Take Photo    opens the device camera in the page (a phone/tablet's back camera, a laptop's webcam): live view ->
//                 take the picture -> a preview with Retake / Use This Photo.
//   Choose Photo  the ordinary file chooser, which is also what a desktop uses.
// If the camera is missing, blocked, refused, or the page is not on a secure address, the person is told once, in one
// sentence, and Choose Photo carries on: nothing is lost and the workflow continues.
// Every picture is shrunk in the browser to a JPEG under the server's 2 MB limit first, because a modern phone photo is
// several MB; the server still decodes, checks, shrinks and re-encodes it (SafeUpload) and refuses anything else.
//
//   accepted    a File already chosen in this session (registration keeps it until the last step)
//   currentUrl  the photo already saved on the record (the profile)
//   onUse(file) called when the person presses "Use This Photo"; the parent stores or uploads it
//   onRemove    shows "Remove photo" (the parent decides what removing means)

const OK_TYPES = ['image/jpeg', 'image/png'];
const OK_EXT = ['jpg', 'jpeg', 'png'];
const MAX_INPUT_BYTES = 25 * 1024 * 1024;
const MAX_OUTPUT_BYTES = 1.9 * 1024 * 1024;

class PhotoProblem extends Error {
    constructor(code) { super(code); this.code = code; }
}

async function decode(blob) {
    if (window.createImageBitmap) {
        try { return await createImageBitmap(blob, { imageOrientation: 'from-image' }); } catch { /* try the plain way below */ }
        try { return await createImageBitmap(blob); } catch { /* fall through to <img> */ }
    }
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(blob);
        const img = new Image();
        img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
        img.onerror = () => { URL.revokeObjectURL(url); reject(new PhotoProblem('unreadable')); };
        img.src = url;
    });
}

/** Any picture (file or camera frame) -> a JPEG File no larger than 1280px and under the upload limit. */
export async function preparePhoto(blob) {
    const name = blob.name ?? '';
    const ext = name.includes('.') ? name.split('.').pop().toLowerCase() : '';
    if (blob.type ? !OK_TYPES.includes(blob.type) : !OK_EXT.includes(ext)) throw new PhotoProblem('type');
    if (blob.size > MAX_INPUT_BYTES) throw new PhotoProblem('size');

    let bitmap;
    try { bitmap = await decode(blob); } catch { throw new PhotoProblem('unreadable'); }
    const w = bitmap.width || bitmap.naturalWidth;
    const h = bitmap.height || bitmap.naturalHeight;
    if (!w || !h) throw new PhotoProblem('unreadable');

    for (const [side, quality] of [[1280, 0.88], [1280, 0.7], [960, 0.7], [720, 0.6]]) {
        const scale = Math.min(1, side / Math.max(w, h));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(w * scale));
        canvas.height = Math.max(1, Math.round(h * scale));
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        const out = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
        if (out && out.size <= MAX_OUTPUT_BYTES) return new File([out], 'student-photo.jpg', { type: 'image/jpeg' });
    }
    throw new PhotoProblem('size');
}

export default function StudentPhotoField({ accepted = null, currentUrl = null, onUse, onRemove, error, busy = false }) {
    const { t } = useTranslation();
    const videoRef = useRef(null);
    const streamRef = useRef(null);
    const fileRef = useRef(null);
    const [mode, setMode] = useState('idle');           // idle | starting | live | review
    const [candidate, setCandidate] = useState(null);   // { file, url, source: 'camera' | 'file' }
    const [notice, setNotice] = useState(null);         // 'camera' | 'type' | 'size' | 'unreadable'
    const [facing, setFacing] = useState('environment');
    const [cameras, setCameras] = useState(0);
    const [working, setWorking] = useState(false);

    const acceptedUrl = useMemo(() => (accepted ? URL.createObjectURL(accepted) : null), [accepted]);
    useEffect(() => () => { if (acceptedUrl) URL.revokeObjectURL(acceptedUrl); }, [acceptedUrl]);
    useEffect(() => () => { if (candidate?.url) URL.revokeObjectURL(candidate.url); }, [candidate]);

    const stopCamera = useCallback(() => {
        streamRef.current?.getTracks().forEach((track) => track.stop());
        streamRef.current = null;
        if (videoRef.current) videoRef.current.srcObject = null;
    }, []);
    useEffect(() => stopCamera, [stopCamera]);

    const startCamera = async (side) => {
        setNotice(null);
        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
            setNotice('camera');
            setMode('idle');
            return;
        }
        stopCamera();
        setMode('starting');
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: side }, width: { ideal: 1280 }, height: { ideal: 960 } }, audio: false });
            streamRef.current = stream;
            setFacing(side);
            setMode('live');
            try { setCameras((await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'videoinput').length); } catch { setCameras(0); }
        } catch {
            stopCamera();
            setNotice('camera');
            setMode('idle');
        }
    };

    // attach the stream once the <video> exists
    useEffect(() => {
        if (mode === 'live' && videoRef.current && streamRef.current) {
            videoRef.current.srcObject = streamRef.current;
            videoRef.current.play().catch(() => {});
        }
    }, [mode]);

    const review = (file, source) => {
        setCandidate({ file, url: URL.createObjectURL(file), source });
        setMode('review');
    };

    const prepare = async (blob, source) => {
        setWorking(true);
        setNotice(null);
        try {
            review(await preparePhoto(blob), source);
        } catch (e) {
            setNotice(e instanceof PhotoProblem ? e.code : 'unreadable');
            setMode('idle');
        } finally {
            setWorking(false);
        }
    };

    const snap = () => {
        const video = videoRef.current;
        if (!video || !video.videoWidth) return;
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);
        stopCamera();
        canvas.toBlob((blob) => (blob ? prepare(new File([blob], 'camera.jpg', { type: 'image/jpeg' }), 'camera') : (setNotice('unreadable'), setMode('idle'))), 'image/jpeg', 0.92);
    };

    const cancel = () => { stopCamera(); setCandidate(null); setMode('idle'); };
    const retake = () => { const source = candidate?.source; setCandidate(null); source === 'camera' ? startCamera(facing) : fileRef.current?.click(); };
    const use = () => { const file = candidate.file; setCandidate(null); setMode('idle'); onUse(file); };
    const onFile = (e) => {
        const file = e.target.files?.[0];
        e.target.value = '';
        if (file) { stopCamera(); prepare(file, 'file'); }
    };

    const shown = mode === 'review' ? candidate?.url : acceptedUrl ?? currentUrl;
    const hasPhoto = Boolean(acceptedUrl || currentUrl);
    const size = 'h-64 w-64 max-w-full';

    return (
        <div className="space-y-4" data-testid="photo-field" data-mode={mode}>
            <div className={`relative mx-auto flex ${size} items-center justify-center overflow-hidden rounded-2xl border-2 border-stone-300 bg-stone-100 dark:border-stone-600 dark:bg-stone-900/40 sm:mx-0`} data-testid="photo-box">
                {(mode === 'live' || mode === 'starting') && (
                    <video ref={videoRef} playsInline muted autoPlay className={`h-full w-full object-cover ${facing === 'user' ? '[transform:scaleX(-1)]' : ''}`} data-testid="camera-video" />
                )}
                {mode !== 'live' && mode !== 'starting' && (shown
                    ? <img src={shown} alt={t('photo.alt')} className="h-full w-full object-cover" data-testid="photo-preview" />
                    : (
                        <div className="p-4 text-center text-stone-600 dark:text-stone-400" data-testid="photo-placeholder">
                            <UserCircleIcon className="mx-auto h-24 w-24 text-stone-400 dark:text-stone-500" aria-hidden="true" />
                            <p className="mt-1 text-base">{t('photo.none_yet')}</p>
                        </div>
                    ))}
                {mode === 'starting' && <p className="absolute inset-x-0 bottom-3 text-center text-base font-medium text-white drop-shadow">{t('photo.starting')}</p>}
                {working && <p className="absolute inset-0 flex items-center justify-center bg-white/80 text-base font-semibold text-stone-900 dark:bg-stone-900/80 dark:text-stone-100">{t('photo.preparing')}</p>}
            </div>

            {notice === 'camera' && <div data-testid="camera-notice"><Alert tone="info">{t('photo.camera_unavailable')}</Alert></div>}
            {['type', 'size', 'unreadable'].includes(notice) && <div data-testid="photo-notice"><Alert tone="danger">{t(`photo.err_${notice}`)}</Alert></div>}
            {error && <div data-testid="photo-error"><Alert tone="danger">{error}</Alert></div>}
            {mode === 'review' && <p className="text-base text-stone-700 dark:text-stone-300">{t('photo.review_hint')}</p>}
            {mode === 'live' && <p className="text-base text-stone-700 dark:text-stone-300">{t('photo.live_hint')}</p>}

            <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                {mode === 'idle' && (
                    <>
                        <Button icon={CameraIcon} onClick={() => startCamera(facing)} disabled={working || busy} data-testid="take-photo">{t('photo.take')}</Button>
                        <Button variant="secondary" icon={PhotoIcon} onClick={() => fileRef.current?.click()} disabled={working || busy} data-testid="choose-photo">{t('photo.choose')}</Button>
                        {hasPhoto && onRemove && <Button variant="quiet" icon={TrashIcon} onClick={onRemove} disabled={working || busy} data-testid="remove-photo">{t('photo.remove')}</Button>}
                    </>
                )}
                {(mode === 'live' || mode === 'starting') && (
                    <>
                        <Button icon={CameraIcon} onClick={snap} disabled={mode !== 'live'} data-testid="snap">{t('photo.snap')}</Button>
                        {cameras > 1 && <Button variant="secondary" icon={ArrowPathIcon} onClick={() => startCamera(facing === 'environment' ? 'user' : 'environment')} data-testid="turn-camera">{t('photo.turn')}</Button>}
                        <Button variant="secondary" icon={XMarkIcon} onClick={cancel} data-testid="cancel-camera">{t('photo.cancel')}</Button>
                    </>
                )}
                {mode === 'review' && (
                    <>
                        <Button variant="secondary" icon={ArrowPathIcon} onClick={retake} data-testid="retake">{candidate?.source === 'camera' ? t('photo.retake') : t('photo.choose_other')}</Button>
                        <Button icon={CheckIcon} onClick={use} busy={busy} data-testid="use-photo">{t('photo.use')}</Button>
                    </>
                )}
            </div>

            <input ref={fileRef} type="file" accept="image/jpeg,image/png" className="sr-only" tabIndex={-1} aria-hidden="true" data-testid="photo-file" onChange={onFile} />
        </div>
    );
}
