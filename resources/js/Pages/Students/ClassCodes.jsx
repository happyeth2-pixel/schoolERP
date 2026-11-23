import { BackLink, Card, Page, PrintArea, PrintButton } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { QRCodeSVG } from 'qrcode.react';

// A printable sheet, one row per student: their own report-card lookup code plus a QR code that goes
// straight to the same public page (see ReportCardLookupController) -- cut apart and handed out, or
// posted on a notice board, so a family can check results themselves without an account or a staff visit.
export default function ClassCodes({ section, school, students }) {
    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-stone-800 dark:text-stone-200">Report Card Codes — {section.name}</h2>}>
            <Head title={`Report Card Codes — ${section.name}`} />
            <Page
                title={`Report Card Codes — ${section.name}`}
                description="One printable code and QR code per student. Anyone with a code can look up that student's published report card at the school website -- no account needed."
                back={<BackLink href={route('sections.ranking', section.id)}>Back to class ranking</BackLink>}
                actions={<PrintButton label="Print this sheet" hint={false} />}
            >
                <PrintArea>
                    <div className="mb-4 hidden text-center print:block">
                        <p className="text-lg font-bold">{school}</p>
                        <p className="text-base">{section.name} — {section.academic_year}</p>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {students.map((s) => (
                            <Card key={s.id} className="flex items-center gap-4 break-inside-avoid">
                                <QRCodeSVG value={s.lookup_url} size={84} className="shrink-0" />
                                <div className="min-w-0">
                                    <p className="text-base font-bold leading-snug text-stone-900 dark:text-stone-100">{s.name}</p>
                                    <p className="text-sm text-stone-500 dark:text-stone-400">{s.student_id_number}</p>
                                    <p className="mt-1 whitespace-nowrap font-mono text-lg font-bold tracking-wider text-brand-700 dark:text-brand-400">{s.code}</p>
                                    <p className="text-xs text-stone-500 dark:text-stone-400">Scan, or enter this code at the school website's homepage.</p>
                                </div>
                            </Card>
                        ))}
                    </div>
                </PrintArea>
            </Page>
        </AuthenticatedLayout>
    );
}
