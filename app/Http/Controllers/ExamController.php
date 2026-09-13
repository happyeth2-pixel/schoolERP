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
                'term' => $e->term->name,
                'academic_year' => $e->term->academicYear->name,
                'is_current_year' => $e->term->academicYear->is_current,
            ]),
            'terms' => $school->academicYears()
                ->where('is_current', true)
                ->with('terms')
                ->get()
                ->flatMap(fn ($y) => $y->terms->map(fn ($t) => ['id' => $t->id, 'label' => "{$y->name} — {$t->name}"])),
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

        $user->school->exams()->create($validated);

        return back()->with('success', 'Exam created.');
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

        $exam->update($validated);

        return back()->with('success', 'Exam updated.');
    }

    public function destroy(Request $request, Exam $exam): RedirectResponse
    {
        $request->user()->can('manage-grading-scale') || abort(403);
        $exam->school_id === $request->user()->school_id || abort(403);

        $exam->delete();

        return back()->with('success', 'Exam removed.');
    }
}
