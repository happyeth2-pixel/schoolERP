import Card3D from '@/Components/Card3D';
import DonutChart from '@/Components/charts/DonutChart';
import GroupedBarChart from '@/Components/charts/GroupedBarChart';
import { useTranslation } from 'react-i18next';

// A quick statistical snapshot of the student body -- a male/female headcount per academic year, and a
// current grade split -- shown to every role that already sees a school-wide "total students" figure
// (owner, branch_admin, auditor, accountant), not owner-only. Nothing here is permission-gated beyond
// that: it's the same scope DashboardController::studentDemographics() already limits total_students to.
export default function StudentDemographics({ demographics }) {
    const { t } = useTranslation();
    if (!demographics || demographics.total === 0) return null;

    const gradeSegments = demographics.by_grade.map((g) => ({ label: g.grade, value: g.count }));

    const genderSeries = [
        { key: 'male', label: t('dashboard.gender_male'), color: '#2a78d6' },
        { key: 'female', label: t('dashboard.gender_female'), color: '#e87ba4' },
    ];

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <Card3D glow="blue" className="p-4">
                <h3 className="mb-2 text-sm font-semibold text-stone-500 dark:text-stone-400">{t('dashboard.students_by_gender')}</h3>
                <GroupedBarChart data={demographics.by_year_gender} series={genderSeries} xKey="year" emptyLabel={t('dashboard.no_years_yet')} width={280} height={150} />
            </Card3D>

            {gradeSegments.length > 0 && (
                <Card3D glow="purple" className="p-4">
                    <h3 className="mb-2 text-sm font-semibold text-stone-500 dark:text-stone-400">{t('dashboard.students_by_grade')}</h3>
                    <DonutChart segments={gradeSegments} donut={false} size={110} />
                </Card3D>
            )}
        </div>
    );
}
