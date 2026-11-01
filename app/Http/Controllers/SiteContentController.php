<?php

namespace App\Http\Controllers;

use App\Models\SiteContent;
use App\Support\SiteContentSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Lets the owner/branch admin rewrite the text on the public website (Home/About/Contact) themselves,
    no developer needed -- see App\Support\SiteContentSchema for the fixed list of what can be edited. */
class SiteContentController extends Controller
{
    private function authorize(Request $request): void
    {
        $request->user()->can('manage-school-settings') || abort(403);
    }

    public function edit(Request $request, string $page): Response
    {
        $this->authorize($request);
        $pages = SiteContentSchema::pages();
        abort_unless(isset($pages[$page]), 404);

        return Inertia::render('SiteContent/Edit', [
            'page' => $page,
            'pageLabels' => SiteContentSchema::pageLabels(),
            'fields' => $pages[$page],
            'values' => SiteContentSchema::forPage($page),
        ]);
    }

    public function update(Request $request, string $page): RedirectResponse
    {
        $this->authorize($request);
        $fields = SiteContentSchema::pages()[$page] ?? null;
        abort_unless($fields !== null, 404);

        $rules = [];
        foreach ($fields as $key => $def) {
            $rules[$key] = ['required', 'string', $def['type'] === 'textarea' ? 'max:4000' : 'max:300'];
        }
        $validated = $request->validate($rules);

        foreach ($validated as $key => $value) {
            SiteContent::updateOrCreate(['page' => $page, 'field_key' => $key], ['value' => $value]);
        }

        return back()->with('success', 'Website text updated.');
    }
}
