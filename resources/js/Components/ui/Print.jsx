import Modal from '@/Components/Modal';
import { ArrowDownTrayIcon, PrinterIcon, XMarkIcon } from '@heroicons/react/24/outline';
import { useCallback, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Button from './Button';

// Printing here means the browser's own print dialog (there is no separate "download PDF" for these
// documents), and many people do not know that dialog can also save a file. So a print action always
// (1) names the document, and (2) says in one sentence what is about to open and how to keep a file.

/** <PrintButton label="Print ID card" /> -- `label` is required; "Print" alone does not say what. */
export function PrintButton({ label, hint = true, variant = 'secondary', className = '' }) {
    const { t } = useTranslation();

    return (
        <div className={className}>
            <Button variant={variant} icon={PrinterIcon} onClick={() => window.print()}>{label}</Button>
            {hint && <p className="mt-2 text-sm text-stone-600 dark:text-stone-400">{t('ui.print_hint')}</p>}
        </div>
    );
}

/**
 * A receipt, certificate, report card or statement, shown in an on-page popup instead of the browser
 * just downloading a file or replacing the whole screen with a bare PDF -- the same document either way
 * (the URL is a real PDF, loaded inline so the browser's own viewer shows it, with its own Print/Download
 * icons as a bonus on desktop), but now with an explicit Download button that works the same on a phone
 * as on a computer, and a Close button to get back to the page underneath without a back-button.
 *
 * const [open, modal] = usePdfPreview();
 * <Button onClick={() => open({ url: route('payments.receipt', payment.id), title: 'Receipt' })}>Print</Button>
 * {modal}
 */
export function usePdfPreview() {
    const [state, setState] = useState(null);
    const [visible, setVisible] = useState(false);
    const open = useCallback((opts) => { setState(opts); setVisible(true); }, []);
    // Only hide (Modal's own ~200ms fade); `state` -- and so the iframe's url/title -- stays put
    // while that runs, so the panel never re-renders mid-fade with props that have gone away.
    const close = useCallback(() => setVisible(false), []);

    return [open, state ? <PdfPreviewModal key={state.url} {...state} show={visible} onClose={close} /> : null];
}

function PdfPreviewModal({ url, title, filename, show, onClose }) {
    const { t } = useTranslation();
    const iframeRef = useRef(null);
    const downloadUrl = url + (url.includes('?') ? '&' : '?') + 'download=1';

    const print = () => {
        try {
            iframeRef.current.contentWindow.focus();
            iframeRef.current.contentWindow.print();
        } catch {
            // Some mobile browsers refuse to script an embedded PDF viewer -- opening it full-page still
            // lets the person use that browser's own print/share option, which always works.
            window.open(url, '_blank');
        }
    };

    return (
        <Modal show={show} maxWidth="4xl" onClose={onClose}>
            <div className="flex h-[85vh] flex-col">
                <div className="flex shrink-0 items-center justify-between gap-3 border-b border-stone-200 px-4 py-3 dark:border-stone-700">
                    <h2 className="min-w-0 truncate text-lg font-semibold text-stone-900 dark:text-stone-100">{title}</h2>
                    <button type="button" onClick={onClose} aria-label={t('common.close')} className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-stone-500 hover:bg-stone-100 dark:text-stone-400 dark:hover:bg-stone-700">
                        <XMarkIcon className="h-6 w-6" aria-hidden="true" />
                    </button>
                </div>
                <div className="min-h-0 flex-1 bg-stone-200 dark:bg-stone-900">
                    {show && <iframe ref={iframeRef} src={url} title={title} className="h-full w-full border-0" />}
                </div>
                <div className="flex shrink-0 flex-col gap-2 border-t border-stone-200 p-4 sm:flex-row sm:justify-end dark:border-stone-700">
                    <Button variant="secondary" fullWidth icon={ArrowDownTrayIcon} as="a" href={downloadUrl} download={filename} className="sm:w-auto">{t('ui.download')}</Button>
                    <Button icon={PrinterIcon} onClick={print} fullWidth className="sm:w-auto">{t('ui.print')}</Button>
                    <Button variant="quiet" onClick={onClose} fullWidth className="sm:w-auto">{t('common.close')}</Button>
                </div>
            </div>
        </Modal>
    );
}

/** Wrap what should appear on paper; everything else on the page is hidden when printing. */
export function PrintArea({ children, className = '' }) {
    return (
        <>
            <style>{`
                @media print {
                    body * { visibility: hidden; }
                    [data-print-area], [data-print-area] * { visibility: visible; }
                    [data-print-area] { position: absolute; top: 0; left: 0; }
                }
            `}</style>
            <div data-print-area className={className}>{children}</div>
        </>
    );
}
