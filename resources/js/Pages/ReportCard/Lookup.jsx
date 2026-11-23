import MarketingLayout from '@/Layouts/MarketingLayout';
import { AcademicCapIcon, ExclamationTriangleIcon, MagnifyingGlassIcon, PrinterIcon } from '@heroicons/react/24/outline';
import { Head, router, useForm } from '@inertiajs/react';

const inputClass = 'w-full rounded-xl border-2 border-[var(--ph-beige)] bg-white px-4 py-4 text-center text-xl font-bold uppercase tracking-[0.2em] text-[var(--ph-ink)] outline-none transition focus:border-[var(--ph-gold)] focus:ring-4 focus:ring-[var(--ph-gold)]/15';

function CodeForm({ autoFocus = true }) {
    const { data, setData, processing } = useForm({ code: '' });

    const submit = (e) => {
        e.preventDefault();
        if (!data.code.trim()) return;
        router.visit(route('report-card.lookup.show', data.code.trim()));
    };

    return (
        <form onSubmit={submit} className="mx-auto max-w-sm space-y-4">
            <input
                type="text"
                value={data.code}
                onChange={(e) => setData('code', e.target.value)}
                placeholder="e.g. ABCD1-23456"
                autoFocus={autoFocus}
                className={inputClass}
            />
            <button type="submit" disabled={processing} className="ph-btn-gold flex w-full items-center justify-center gap-2 rounded-xl px-5 py-3.5 text-base font-semibold text-[#2a2008]">
                <MagnifyingGlassIcon className="h-5 w-5" /> View Report Card
            </button>
        </form>
    );
}

function StateMessage({ icon: Icon, title, body }) {
    return (
        <div className="mx-auto max-w-md rounded-2xl bg-white/90 p-8 text-center shadow-sm">
            <Icon className="mx-auto h-10 w-10 text-[var(--ph-gold)]" aria-hidden="true" />
            <h2 className="mt-4 font-display text-xl font-semibold text-[var(--ph-ink)]">{title}</h2>
            <p className="mt-2 text-base text-[var(--ph-ink)]/70">{body}</p>
        </div>
    );
}

function ReportCardResult({ school, report }) {
    return (
        <div className="mx-auto max-w-2xl rounded-2xl bg-white p-6 shadow-sm sm:p-8 print:shadow-none">
            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--ph-beige)] pb-4">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wider text-[var(--ph-gold)]">{school}</p>
                    <h2 className="font-display text-2xl font-semibold text-[var(--ph-ink)]">{report.student.name}</h2>
                    <p className="text-sm text-[var(--ph-ink)]/60">{report.student.student_id_number} · {report.section} · {report.academic_year}</p>
                </div>
                <button type="button" onClick={() => window.print()} className="flex items-center gap-2 rounded-full border-2 border-[var(--ph-beige)] px-4 py-2 text-sm font-semibold text-[var(--ph-ink)] print:hidden">
                    <PrinterIcon className="h-4 w-4" /> Print
                </button>
            </div>

            <table className="mt-5 w-full text-left text-sm">
                <thead>
                    <tr className="text-xs uppercase tracking-wide text-[var(--ph-ink)]/50">
                        <th className="pb-2">Subject</th>
                        <th className="pb-2 text-right">Average</th>
                        <th className="pb-2 text-right">Grade</th>
                    </tr>
                </thead>
                <tbody>
                    {report.subjects.map((s) => (
                        <tr key={s.subject} className="border-t border-[var(--ph-beige)]">
                            <td className="py-2 text-[var(--ph-ink)]">{s.subject}</td>
                            <td className="py-2 text-right font-semibold text-[var(--ph-ink)]">{s.average ?? '—'}</td>
                            <td className="py-2 text-right text-[var(--ph-ink)]/70">{s.letter ?? '—'}</td>
                        </tr>
                    ))}
                </tbody>
            </table>

            <div className="mt-6 flex flex-wrap items-center justify-between gap-4 rounded-xl bg-[var(--ph-beige)]/60 p-5">
                <div>
                    <p className="text-sm text-[var(--ph-ink)]/60">Overall Average</p>
                    <p className="text-3xl font-bold text-[var(--ph-forest)]">{report.overall_average !== null ? `${report.overall_average}%` : '—'}</p>
                </div>
                <div className="text-right">
                    <p className="text-sm text-[var(--ph-ink)]/60">Class Rank</p>
                    <p className="text-3xl font-bold text-[var(--ph-forest)]">{report.rank ?? '—'}{report.class_size ? ` of ${report.class_size}` : ''}</p>
                </div>
            </div>

            {report.rank === null && report.rank_reasons?.length > 0 && (
                <div className="mt-4 rounded-xl border-2 border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                    <p className="font-semibold">Not ranked yet — still missing:</p>
                    <ul className="mt-1 list-disc pl-5">
                        {report.rank_reasons.map((r, i) => <li key={i}>{r}</li>)}
                    </ul>
                </div>
            )}
        </div>
    );
}

// The public homepage's "Enter your code" result page -- no login, same as the ID-card QR's public
// verification page. `state` is one of: undefined (just show the form), 'invalid', 'unavailable', 'found'.
export default function Lookup({ state, school, report }) {
    return (
        <MarketingLayout>
            <Head title="Report Card Lookup" />

            <section className="relative overflow-hidden px-4 pb-20 pt-32 sm:px-6 sm:pt-40 lg:px-8" style={{ background: 'radial-gradient(120% 90% at 15% 0%, #123f31 0%, var(--ph-forest) 45%, var(--ph-navy) 100%)' }}>
                <div className="relative mx-auto max-w-3xl text-center">
                    <span className="ph-glass inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-xs font-medium tracking-wide text-white/85">
                        <AcademicCapIcon className="h-4 w-4" /> Report Card Lookup
                    </span>
                    <h1 className="mt-6 font-display text-4xl font-semibold leading-tight text-white sm:text-5xl">Enter your code</h1>
                    <p className="mx-auto mt-4 max-w-xl text-lg leading-relaxed text-white/70">
                        Type the code from your class's report-card sheet, or scan its QR code, to see a student's published report card — no account needed.
                    </p>

                    <div className="mt-8">
                        <CodeForm />
                    </div>
                </div>
            </section>

            <section className="px-4 py-16 sm:px-6 lg:px-8">
                {state === 'invalid' && (
                    <StateMessage icon={ExclamationTriangleIcon} title="Code not recognized" body="Double-check the code and try again, or ask the school office for a fresh copy." />
                )}
                {state === 'unavailable' && (
                    <StateMessage icon={ExclamationTriangleIcon} title="Nothing to show yet" body={`${school ? `${school}: ` : ''}This code is valid, but there's no current class or published report card for it yet.`} />
                )}
                {state === 'found' && <ReportCardResult school={school} report={report} />}
            </section>
        </MarketingLayout>
    );
}
