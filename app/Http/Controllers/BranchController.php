<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    public function index(Request $request): Response
    {
        $request->user()->can('manage-branches') || abort(403);

        return Inertia::render('Branches/Index', [
            'branches' => $request->user()->school->branches()->withCount('users')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->can('manage-branches') || abort(403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_main' => ['boolean'],
        ]);

        $request->user()->school->branches()->create($validated);

        return back()->with('success', 'Branch created.');
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $request->user()->can('manage-branches') || abort(403);
        $branch->school_id === $request->user()->school_id || abort(403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_main' => ['boolean'],
        ]);

        $branch->update($validated);

        return back()->with('success', 'Branch updated.');
    }
}
