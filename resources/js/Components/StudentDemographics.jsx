import DonutChart from '@/Components/charts/DonutChart';
import GlassPanel from '@/Components/GlassPanel';
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
            <GlassPanel index={0} title={t('dashboard.students_by_gender')}>
                <GroupedBarChart data={demographics.by_year_gender} series={genderSeries} xKey="year" emptyLabel={t('dashboard.no_years_yet')} width={280} height={150} />
            </GlassPanel>

            {gradeSegments.length > 0 && (
                <GlassPanel index={1} title={t('dashboard.students_by_grade')}>
                    <DonutChart segments={gradeSegments} donut={false} size={110} />
                </GlassPanel>
            )}
        </div>
    );
}
