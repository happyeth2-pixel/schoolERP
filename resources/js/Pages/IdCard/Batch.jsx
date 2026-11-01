import IdCardFace from '@/Components/IdCardFace';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BackLink, Button, Card, EmptyState, Page, PrintArea, PrintButton } from '@/Components/ui';
import { IdentificationIcon } from '@heroicons/react/24/outline';
import { Head } from '@inertiajs/react';
import { Fragment, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

// One A4 sheet at a time: 4 students, each printed twice (left and right) so the sheet can be cut into
// two identical stacks of cards -- 8 cards per sheet, sized to actually fill an A4 page (90mm x 63mm,
// bigger than a wallet-sized CR80 card) rather than leaving most of the page blank under 3 small ones.
// "Next" moves to the next 4 students; only the sheet on screen is ever sent to the printer (PrintArea
// already hides everything else), matching how a registrar actually works through a class -- one sheet
// of card stock loaded, printed, then the next.

const SHEET_SIZE = 4;
// 2 columns x 4 rows of these, a 12mm page margin and 6mm gaps fill an A4 sheet (210 x 297mm) with a
// couple of mm to spare -- not pushed out to the exact edge, which some printers clip regardless of what
// the page margin says. If this ever changes, the literal `w-[90mm]` classes below must match CARD_W_MM
// (a Tailwind arbitrary-value class has to be a literal string for the build to generate it -- a template
// literal built from the constant would compile to nothing).
const CARD_W_MM = 90;
const CARD_H_MM = 63;

function chunk(items, size) {
    const groups = [];
    for (let i = 0; i < items.length; i += size) groups.push(items.slice(i, i + size));

    return groups;
}

export default function Batch({ students, school, section }) {
    const { t } = useTranslation();
    const sheets = useMemo(() => chunk(students, SHEET_SIZE), [students]);
    const [page, setPage] = useState(0);
    const title = t('idcard.batch_title_with_section', { section: section.label });

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">{t('idcard.batch_page_title')}</h2>}>
            <Head title={title} />

            <Page title={title} description={t('idcard.batch_desc')} width="wide" back={<BackLink href={route('academic-structure.index')}>{t('idcard.batch_back')}</BackLink>}>
                {sheets.length === 0 ? (
                    <Card><EmptyState icon={IdentificationIcon} title={t('idcard.batch_empty_title')}>{t('idcard.batch_empty_body')}</EmptyState></Card>
                ) : (
                    <div className="space-y-4">
                        <div className="flex flex-wrap items-center justify-between gap-3" data-testid="batch-pager">
                            <Button variant="secondary" onClick={() => setPage((p) => Math.max(0, p - 1))} disabled={page === 0}>{t('common.previous')}</Button>
                            <p className="text-base font-semibold text-stone-900 dark:text-stone-100" data-testid="sheet-label">
                                {t('idcard.batch_sheet_of', { current: page + 1, total: sheets.length })}
                            </p>
                            <Button variant="secondary" onClick={() => setPage((p) => Math.min(sheets.length - 1, p + 1))} disabled={page === sheets.length - 1}>{t('common.next')}</Button>
                        </div>

                        <PrintButton label={t('idcard.batch_print_sheet')} variant="primary" />

                        <div className="flex justify-center overflow-x-auto">
                            <PrintArea className="id-card-sheet grid grid-cols-2 gap-4 rounded-xl border border-stone-200 bg-white p-4 shadow-sm dark:border-stone-700 dark:bg-stone-800" data-testid="id-card-sheet">
                                {sheets[page].map((student) => (
                                    <Fragment key={student.id}>
                                        <IdCardFace student={student} school={school} widthMm={CARD_W_MM} heightMm={CARD_H_MM} spacious className="w-[90mm]" />
                                        <IdCardFace student={student} school={school} widthMm={CARD_W_MM} heightMm={CARD_H_MM} spacious className="w-[90mm]" />
                                    </Fragment>
                                ))}
                            </PrintArea>
                        </div>
                    </div>
                )}
            </Page>

            <style>{`
                @media print {
                    @page { size: A4; margin: 12mm; }
                    .id-card-sheet {
                        gap: 6mm !important;
                        padding: 0 !important;
                        border: none !important;
                        box-shadow: none !important;
                    }
                    .id-card-print {
                        width: var(--card-w, 90mm) !important;
                        height: var(--card-h, 63mm) !important;
                        max-width: none !important;
                        aspect-ratio: auto !important;
                        box-shadow: none !important;
                        border-width: 0.3mm !important;
                    }
                }
            `}</style>
        </AuthenticatedLayout>
    );
}
