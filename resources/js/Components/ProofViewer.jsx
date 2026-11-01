import { Alert, Button } from '@/Components/ui';
import { ArrowPathIcon, ArrowTopRightOnSquareIcon, DocumentTextIcon, MagnifyingGlassMinusIcon, MagnifyingGlassPlusIcon } from '@heroicons/react/24/outline';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

// The payment proof, made easy to inspect: an image is shown large on the page (zoom, turn it if the photo is
// sideways, open it full size in a new tab); a PDF opens in a new tab with one big button (the proof is served
// with a locked-down sandbox header, which is why it is not embedded); no proof says so plainly.
// The file itself is served by PaymentController@proof, whose authorization and security headers are unchanged.

export default function ProofViewer({ url, kind }) {
    const { t } = useTranslation();
    const [zoomed, setZoomed] = useState(false);
    const [turns, setTurns] = useState(0);

    if (!kind) {
        return <Alert tone="info" title={t('verify.proof_none_title')}>{t('verify.proof_none_body')}</Alert>;
    }

    if (kind === 'pdf') {
        return (
            <div className="flex flex-col items-center gap-4 rounded-xl border-2 border-dashed border-stone-300 p-8 text-center dark:border-stone-600" data-testid="proof-pdf">
                <DocumentTextIcon className="h-16 w-16 text-stone-500" aria-hidden="true" />
                <p className="text-lg font-semibold text-stone-900 dark:text-stone-100">{t('verify.proof_pdf_title')}</p>
                <p className="text-base text-stone-700 dark:text-stone-300">{t('verify.proof_pdf_body')}</p>
                <Button as="a" href={url} target="_blank" rel="noreferrer" icon={ArrowTopRightOnSquareIcon}>{t('verify.proof_open_pdf')}</Button>
            </div>
        );
    }

    return (
        <div data-testid="proof-image">
            <div className="flex min-h-[18rem] items-center justify-center overflow-auto rounded-xl border-2 border-stone-300 bg-stone-100 p-2 dark:border-stone-600 dark:bg-stone-900" style={{ maxHeight: '75vh' }}>
                <img
                    src={url}
                    alt={t('verify.proof_alt')}
                    data-zoomed={zoomed}
                    style={{ transform: `rotate(${turns * 90}deg)` }}
                    className={`select-none transition-transform ${zoomed ? 'max-w-none shrink-0' : 'max-h-[70vh] w-full object-contain'}`}
                    {...(zoomed ? { width: 1400 } : {})}
                />
            </div>
            <div className="mt-3 flex flex-wrap gap-3">
                <Button variant="secondary" size="md" icon={zoomed ? MagnifyingGlassMinusIcon : MagnifyingGlassPlusIcon} onClick={() => setZoomed((z) => !z)}>{zoomed ? t('verify.proof_zoom_out') : t('verify.proof_zoom_in')}</Button>
                <Button variant="secondary" size="md" icon={ArrowPathIcon} onClick={() => setTurns((n) => (n + 1) % 4)}>{t('verify.proof_turn')}</Button>
                <Button as="a" variant="secondary" size="md" href={url} target="_blank" rel="noreferrer" icon={ArrowTopRightOnSquareIcon}>{t('verify.proof_open_full')}</Button>
            </div>
        </div>
    );
}
