import { Button, Card, EmptyState, FlashMessages, Page, useConfirm } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { CurrencyDollarIcon, PlusIcon } from '@heroicons/react/24/outline';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

// The school's fees, by grade for one school year: what each grade is billed, in words, with Change (the guided
// page) and Remove (asks first, and says bills already made are not touched). Adding is the guided page too.
// Same data, routes and permission as before.

const money = (n) => Number(n).toLocaleString(undefined, { maximumFractionDigits: 2 });

export default function Index({ feeStructures, branches, grades, academicYears }) {
    const { t } = useTranslation();
    const [ask, confirmDialog] = useConfirm();
    const current = academicYears.find((y) => y.is_current) ?? academicYears[0];
    const [yearId, setYearId] = useState(current ? String(current.id) : '');
    const multiBranch = branches.length > 1;

    const shown = feeStructures.filter((f) => yearId === '' || String(f.academic_year_id) === yearId);
    const byGrade = grades.map((g) => ({ grade: g, fees: shown.filter((f) => f.grade_id === g.id).sort((a, b) => a.name.localeCompare(b.name)) }));
    const withFees = byGrade.filter((x) => x.fees.length > 0);
    const without = byGrade.filter((x) => x.fees.length === 0).map((x) => x.grade.name);

    const remove = async (fee) => {
        const ok = await ask({
            title: t('fee.remove_title', { name: fee.name }),
            body: <p>{t('fee.remove_body', { grade: fee.grade.name })}</p>,
            confirmLabel: t('fee.remove_confirm'),
            cancelLabel: t('fee.remove_cancel'),
            tone: 'danger',
        });
        if (ok) router.delete(route('fee-structures.destroy', fee.id), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-lg font-semibold text-gray-800 dark:text-gray-200">{t('fee.title')}</h2>}>
            <Page
                title={t('fee.title')}
                description={t('fee.index_desc')}
                width="default"
                actions={<Button as={Link} href={route('fee-structures.create')} icon={PlusIcon}>{t('fee.add_button')}</Button>}
            >
                <FlashMessages />

                <Card>
                    <p className="mb-2 text-base font-medium text-gray-900 dark:text-gray-100">{t('fee.year_filter')}</p>
                    <div role="radiogroup" aria-label={t('fee.year_filter')} className="flex flex-wrap gap-2">
                        {[...academicYears.map((y) => [String(y.id), y.name]), ['', t('fee.all_years')]].map(([value, label]) => (
                            <button key={value} type="button" role="radio" aria-checked={yearId === value} onClick={() => setYearId(value)} className={`min-h-[48px] rounded-xl border-2 px-4 text-base font-semibold ${yearId === value ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-300 bg-white text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100'}`}>{label}</button>
                        ))}
                    </div>
                </Card>

                {withFees.length === 0 && (
                    <Card><EmptyState icon={CurrencyDollarIcon} title={t('fee.no_fees_title')} action={<Button as={Link} href={route('fee-structures.create')} icon={PlusIcon}>{t('fee.add_button')}</Button>}>{t('fee.no_fees_body')}</EmptyState></Card>
                )}

                {withFees.map(({ grade, fees }) => (
                    <Card key={grade.id} as="section" aria-label={grade.name} data-testid="grade-card">
                        <h2 className="text-xl font-bold text-gray-900 dark:text-gray-100">{grade.name}</h2>
                        <ul className="mt-2 divide-y divide-gray-200 dark:divide-gray-700">
                            {fees.map((f) => (
                                <li key={f.id} data-testid="fee-row" className="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="min-w-0">
                                        <p className="text-lg font-semibold text-gray-900 dark:text-gray-100">{f.name}</p>
                                        <p className="text-base text-gray-700 dark:text-gray-300">
                                            <span className="font-semibold">{money(f.amount)} ETB</span> · {t(`fee.freq_${f.frequency}`)}
                                            {yearId === '' ? ` · ${f.academic_year.name}` : ''}{multiBranch ? ` · ${f.branch.name}` : ''}
                                        </p>
                                    </div>
                                    <div className="flex gap-3">
                                        <Button as={Link} href={route('fee-structures.edit', f.id)} variant="secondary" size="md">{t('fee.change')}</Button>
                                        <Button variant="quiet" size="md" onClick={() => remove(f)}>{t('fee.remove')}</Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </Card>
                ))}

                {withFees.length > 0 && without.length > 0 && (
                    <p className="text-base text-gray-700 dark:text-gray-300" data-testid="without">{t('fee.grades_without', { grades: without.join(', ') })}</p>
                )}
            </Page>
            {confirmDialog}
        </AuthenticatedLayout>
    );
}
