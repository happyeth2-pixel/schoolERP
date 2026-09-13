<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SchoolSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $request->user()->can('manage-school-settings') || abort(403);

        return Inertia::render('Settings/School', [
            'school' => $request->user()->school,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->user()->can('manage-school-settings') || abort(403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'subcity' => ['nullable', 'string', 'max:255'],
            'woreda' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'calendar_preference' => ['required', 'in:ethiopian,gregorian,both'],
        ]);

        $request->user()->school->update($validated);

        return back()->with('success', 'School settings updated.');
    }
}
