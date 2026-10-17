<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExamController extends Controller
{
    public function index(Request $request): Response
    {
        $request->user()->can('manage-grading-scale') || abort(403);
        $school = $request->user()->school;

        return Inertia::render('Exams/Index', [
            'exams' => $school->exams()->with('term.academicYear')->orderByDesc('id')->get()->map(fn (Exam $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'type' => $e->type,
                'weight_percent' => $e->weight_percent,
                'term_id' => $e->term_id,
                'term' => $e->term->name,
                'academic_year' => $e->term->academicYear->name,
                'is_current_year' => $e->term->academicYear->is_current,
            ]),
            'terms' => $school->academicYears()
                ->where('is_current', true)
                ->with('terms')
                ->get()
                ->flatMap(fn ($y) => $y->terms->map(function ($t) use ($y) {
                    $total = Exam::termWeightTotal($t->id);

                    return [
                        'id' => $t->id,
                        'label' => "{$y->name} — {$t->name}",
                        'weight_total' => $total,
                        'complete' => Exam::isCompleteTotal($total),
                        'locked' => Exam::termHasPublishedResults($t->id),
                    ];
                }))->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->can('manage-grading-scale') || abort(403);
        $user = $request->user();

        $validated = $request->validate([
            'term_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:quiz,assignment,midterm,final,other'],
            'weight_percent' => ['required', 'numeric', 'min:0.01', 'max:100'],
        ]);

        abort_unless(
            $user->school->academicYears()->whereHas('terms', fn ($q) => $q->whereKey($validated['term_id']))->exists(),
            403
        );

        if ($error = $this->weightError((int) $validated['term_id'], (float) $validated['weight_percent'], null, true)) {
            return back()->withErrors($error);
        }

        $user->school->exams()->create($validated);

        $total = Exam::termWeightTotal((int) $validated['term_id']);

        return back()->with('success', 'Exam created. '.$this->totalNote($total));
    }

    public function update(Request $request, Exam $exam): RedirectResponse
    {
        $request->user()->can('manage-grading-scale') || abort(403);
        $exam->school_id === $request->user()->school_id || abort(403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:quiz,assignment,midterm,final,other'],
            'weight_percent' => ['required', 'numeric', 'min:0.01', 'max:100'],
        ]);

        if (Exam::termHasPublishedResults($exam->term_id) && ($validated['name'] !== $exam->name || $validated['type'] !== $exam->type)) {
            return back()->withErrors(['exam' => 'Results are already published for this term, so its exams cannot be changed. Reopen the published results first.']);
        }

        $weightChanged = round((float) $validated['weight_percent'], 2) !== round((float) $exam->weight_percent, 2);

        if ($weightChanged && ($error = $this->weightError($exam->term_id, (float) $validated['weight_percent'], $exam->id, true))) {
            return back()->withErrors($error);
        }

        $exam->update($validated);

        return back()->with('success', 'Exam updated. '.$this->totalNote(Exam::termWeightTotal($exam->term_id)));
    }

    public function destroy(Request $request, Exam $exam): RedirectResponse
    {
        $request->user()->can('manage-grading-scale') || abort(403);
        $exam->school_id === $request->user()->school_id || abort(403);

        if (Exam::termHasPublishedResults($exam->term_id)) {
            return back()->withErrors(['exam' => 'Results are already published for this term, so its exams cannot be removed. Reopen the published results first.']);
        }

        $exam->delete();

        return back()->with('success', 'Exam removed. '.$this->totalNote(Exam::termWeightTotal($exam->term_id)));
    }

    /**
     * Refuses a weight that would push the term above 100%, and any weight
     * change (or new exam) in a term whose results are already published —
     * changing it would silently change every published report card.
     */
    private function weightError(int $termId, float $weight, ?int $exceptExamId, bool $checkLock): ?array
    {
        if ($checkLock && Exam::termHasPublishedResults($termId)) {
            return ['weight_percent' => 'Results are already published for this term, so exam weights are locked. Reopen the published results first.'];
        }

        $others = Exam::termWeightTotal($termId, $exceptExamId);
        if ($others + $weight > Exam::WEIGHT_TOTAL + 0.005) {
            $room = max(0, Exam::WEIGHT_TOTAL - $others);

            return ['weight_percent' => "This term's exams already total {$others}%, so at most {$room}% is left for this one (a term must total exactly 100%)."];
        }

        return null;
    }

    private function totalNote(float $total): string
    {
        return Exam::isCompleteTotal($total)
            ? 'This term now totals 100% — ready to publish results.'
            : "This term now totals {$total}% — it must reach exactly 100% before results can be published.";
    }
}
