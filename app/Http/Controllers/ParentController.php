<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ParentController extends Controller
{
    public function index(Request $request): Response
    {
        $request->user()->canViewOrManage('manage-parents') || abort(403);
        $user = $request->user();

        $parents = User::query()
            ->where('school_id', $user->school_id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'parent'))
            ->with('children')
            ->orderBy('name')
            ->get()
            ->map(fn (User $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'email' => $p->email,
                'phone' => $p->phone,
                'children' => $p->children->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->fullName(),
                    'student_id_number' => $c->student_id_number,
                    'relationship' => $c->pivot->relationship,
                ]),
            ]);

        return Inertia::render('Parents/Index', ['parents' => $parents]);
    }

    /**
     * Small lookup used by the "link an existing parent" flow on the
     * Student form — confirms a parent account exists before the form
     * submits, so the registrar gets fast feedback instead of a
     * server-side validation error after filling in the whole form.
     */
    public function search(Request $request)
    {
        $request->user()->can('manage-students') || abort(403);

        $email = $request->query('email');

        $guardian = User::where('school_id', $request->user()->school_id)
            ->where('email', $email)
            ->whereHas('roles', fn ($q) => $q->where('name', 'parent'))
            ->first(['id', 'name', 'email']);

        return response()->json(['guardian' => $guardian]);
    }
}
