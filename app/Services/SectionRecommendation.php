<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Section Balancing -- academic expansion, Phase 5. The brief's own words: "recommendations, not silent
 * auto-assignment," confirmed by staff. This never picks a section for anyone; it only flags, among the
 * sections a picker is already showing, the one that currently has the fewest students enrolled -- pure
 * headcount comparison among SIBLINGS of the same grade (and same branch/year, when that data is present),
 * exactly the brief's own worked example ("8B currently has the lowest number of students"). Capacity is
 * used only to exclude a full section from ever being "recommended" -- never to weight the comparison by a
 * fill ratio, which nothing in the brief asked for.
 *
 * Works on the exact array shape every section-picking controller already builds (each entry already
 * merged with `SectionCapacity::describe()`, so `occupied`/`full` are already present). Groups by whichever
 * of `branch_id`/`academic_year_id` happen to be present alongside `grade` -- some call sites' own queries
 * are already scoped to one branch/year (grouping by grade alone is then correct); others span every branch
 * or every year for an org-wide actor, where comparing headcounts across branches/years would be
 * meaningless. When `is_current_year` is present (the promotion picker's own flag), only current-year
 * sections are ever candidates -- recommending a destination in a closed year makes no sense.
 */
class SectionRecommendation
{
    /** @param Collection<int, array> $sections */
    public static function annotate(Collection $sections): Collection
    {
        $groupKey = fn (array $s) => implode('|', array_filter([
            $s['grade'] ?? '',
            array_key_exists('branch_id', $s) ? 'b'.$s['branch_id'] : null,
            array_key_exists('academic_year_id', $s) ? 'y'.$s['academic_year_id'] : null,
        ], fn ($v) => $v !== null));

        $inYear = $sections->filter(fn (array $s) => $s['is_current_year'] ?? true);

        // "Is there a real sibling to balance against" is judged against every same-year section in the
        // grade, INCLUDING full ones -- a grade with one open section and one full one still has a real
        // choice being made (should I override the full one, or go to the open one?), unlike a grade with
        // truly only one section, full or not, where there is nothing to compare against at all.
        $siblingCounts = $inYear->groupBy($groupKey)->map->count();

        $recommendedIds = $inYear->filter(fn (array $s) => empty($s['full']))
            ->groupBy($groupKey)
            ->filter(fn (Collection $group, string $key) => ($siblingCounts[$key] ?? 0) >= 2)
            ->flatMap(function (Collection $group) {
                $lowest = $group->pluck('occupied')->min();

                return $group->filter(fn (array $s) => $s['occupied'] === $lowest)
                    ->sortBy('name')
                    ->take(1)
                    ->pluck('id');
            })
            ->all();

        return $sections->map(fn (array $s) => $s + ['recommended' => in_array($s['id'], $recommendedIds, true)]);
    }
}
