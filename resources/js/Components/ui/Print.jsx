import { PrinterIcon } from '@heroicons/react/24/outline';
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
            {hint && <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">{t('ui.print_hint')}</p>}
        </div>
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
