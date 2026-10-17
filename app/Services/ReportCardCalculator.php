<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\GradeBand;
use App\Models\Mark;
use App\Models\MarkBatch;
use App\Models\Section;
use Illuminate\Support\Collection;

/**
 * Report-card arithmetic and the class-ranking rules, in one place.
 *
 * CLASS RANKING RULES (version 1) — every report card prints these:
 *
 *  R1  THE CLASS. Everyone with an ACTIVE or COMPLETED enrollment in this
 *      section, for this academic year. A student who transferred OUT or
 *      withdrew is not in the class; a student who transferred IN is, from
 *      the day they joined.
 *  R2  REQUIRED SUBJECTS. Every subject that has at least one published
 *      result batch for this section. (Only published results count; results
 *      still in draft/submitted/approved, and terms whose exam weights don't
 *      total 100%, are ignored.)
 *  R3  ELIGIBILITY. A student is ranked only with complete published results:
 *      a score for EVERY published exam of EVERY required subject. So a student
 *      who joined after some exams were held, or who has an exam with no score
 *      recorded, is shown their average but is NOT ranked — they are never
 *      compared against classmates on fewer or different exams. The reason is
 *      printed on their report card.
 *  R4  ORDER. Ranked students are ordered by overall average (the plain mean of
 *      their subject averages, 2 decimals). Ties share a rank and the next rank
 *      is skipped (1, 2, 2, 4).
 *  R5  EXPLAINABLE. The result carries its calculation basis (rules version,
 *      class size, ranked count, required subjects, and why anyone was left out).
 */
class ReportCardCalculator
{
    public const RANK_RULES_VERSION = 1;

    /** Enrollment statuses that count as "in the class" for ranking (R1). */
    public const CLASS_STATUSES = ['active', 'completed'];

    /**
     * Weighted subject averages for ONE student, from their published marks.
     * $marks: that student's Mark rows (with batch.exam, batch.subject loaded).
     * NULL-score rows are the "missing" ones, handled per the school's policy.
     */
    public function subjectAverages(Collection $marks, string $policy): array
    {
        $result = [];

        foreach ($marks->groupBy(fn ($m) => $m->batch->subject_id) as $subjectMarks) {
            $weightedSum = 0.0;
            $weightTotal = 0.0;
            $counted = 0;
            $missing = 0;

            foreach ($subjectMarks as $mark) {
                $weight = (float) $mark->batch->exam->weight_percent;
                $percentage = $mark->percentage();

                if ($percentage === null) {
                    $missing++;
                    if ($policy !== 'zero') {
                        continue;
                    }
                    $percentage = 0.0;
                }

                $weightedSum += $percentage * $weight;
                $weightTotal += $weight;
                $counted++;
            }

            $average = $weightTotal > 0 ? round($weightedSum / $weightTotal, 2) : null;
            $first = $subjectMarks->first();

            $result[] = [
                'subject' => $first->batch->subject->name,
                'average' => $average,
                'letter' => $average !== null ? GradeBand::letterFor($first->batch->subject->school_id, $average) : null,
                'assessments_published' => $subjectMarks->count(),
                'assessments_counted' => $counted,
                'assessments_missing' => $missing,
            ];
        }

        return $result;
    }

    public function overallOf(array $subjects): ?float
    {
        $averages = array_values(array_filter(array_column($subjects, 'average'), fn ($a) => $a !== null));

        return count($averages) > 0 ? round(array_sum($averages) / count($averages), 2) : null;
    }

    /** Published marks (NULL scores included) for the given enrollments in one section. */
    public function publishedMarks(iterable $enrollmentIds, int $sectionId, array $excludedTermIds = []): Collection
    {
        return Mark::whereIn('enrollment_id', $enrollmentIds)
            ->whereHas('batch', fn ($q) => $q->where('status', 'published')->where('section_id', $sectionId)
                ->whereHas('exam', fn ($e) => $e->whereNotIn('term_id', $excludedTermIds)))
            ->with('batch.exam', 'batch.subject')
            ->get();
    }

    /**
     * Applies rules R1–R5 and returns the rank of $enrollmentId (or why it
     * has none) together with the calculation basis.
     */
    public function ranking(Section $section, int $enrollmentId, string $policy, array $excludedTermIds = []): array
    {
        // R1: the class
        $pool = Enrollment::where('section_id', $section->id)->whereIn('status', self::CLASS_STATUSES)->pluck('id');

        // R2: required subjects = subjects with published batches, and how many exams each has
        $batches = MarkBatch::where('section_id', $section->id)->where('status', 'published')
            ->whereHas('exam', fn ($e) => $e->whereNotIn('term_id', $excludedTermIds))
            ->with('subject')->get();
        $publishedPerSubject = $batches->groupBy('subject_id')->map->count();
        $subjectNames = $batches->mapWithKeys(fn ($b) => [$b->subject_id => $b->subject->name]);

        $marksByEnrollment = $this->publishedMarks($pool, $section->id, $excludedTermIds)->groupBy('enrollment_id');

        $entries = [];
        foreach ($pool as $id) {
            $marks = $marksByEnrollment->get($id, collect());
            $overall = $this->overallOf($this->subjectAverages($marks, $policy));

            // R3: complete published results in every required subject
            $problems = [];
            foreach ($publishedPerSubject as $subjectId => $published) {
                $rows = $marks->filter(fn ($m) => $m->batch->subject_id === $subjectId);
                $notHeld = $published - $rows->count();
                $noScore = $rows->filter(fn ($m) => $m->score === null)->count();

                if ($notHeld > 0) {
                    $problems[] = "{$subjectNames[$subjectId]}: no result for {$notHeld} of {$published} published exam(s) — held before the student joined this class.";
                }
                if ($noScore > 0) {
                    $problems[] = "{$subjectNames[$subjectId]}: {$noScore} of {$published} published exam(s) have no score.";
                }
            }

            $entries[$id] = [
                'overall' => $overall,
                'eligible' => $publishedPerSubject->isNotEmpty() && empty($problems) && $overall !== null,
                'problems' => $problems,
            ];
        }

        $ranked = collect($entries)->filter(fn ($e) => $e['eligible']);
        $mine = $entries[$enrollmentId] ?? ['overall' => null, 'eligible' => false, 'problems' => ['This student is not in the class list for this section.']];

        // R4: competition ranking (1, 2, 2, 4)
        $rank = $mine['eligible']
            ? 1 + $ranked->filter(fn ($e) => $e['overall'] > $mine['overall'])->count()
            : null;

        $reasons = $mine['problems'];
        if ($publishedPerSubject->isEmpty()) {
            $reasons = ['No results have been published for this class yet.'];
        }

        return [
            'rank' => $rank,
            'ranked_count' => $ranked->count(),
            'pool_size' => $pool->count(),
            'not_ranked_reasons' => $rank === null ? $reasons : [],
            'basis' => [
                'rules_version' => self::RANK_RULES_VERSION,
                'class' => 'Students with an active or completed enrollment in this section this academic year (students who transferred out or withdrew are not counted).',
                'required_subjects' => $subjectNames->values()->all(),
                'eligibility' => 'Ranked only with a score for every published exam of every subject listed above. Students with missing scores, or who joined after some exams were held, show their average but are not ranked.',
                'ordering' => 'Highest overall average first (mean of the subject averages, 2 decimals). Ties share a rank and the next rank is skipped (1, 2, 2, 4).',
                'class_size' => $pool->count(),
                'ranked_count' => $ranked->count(),
                'not_ranked_count' => $pool->count() - $ranked->count(),
            ],
        ];
    }
}
