<?php

namespace App\Http\Controllers;

use App\Models\PaymentAccount;
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
            'school' => array_merge($request->user()->school->toArray(), ['phone' => \App\Support\PhoneNumber::display($request->user()->school->phone)]),
            'missingMarkPolicies' => \App\Models\School::MISSING_MARK_POLICIES,
            'branches' => $request->user()->isOrgWide()
                ? $request->user()->school->branches()->orderBy('name')->get(['id', 'name'])
                : $request->user()->school->branches()->where('id', $request->user()->branch_id)->get(['id', 'name']),
            'canManageSchoolWideAccounts' => $request->user()->isOrgWide(),
            'paymentAccounts' => $request->user()->school->paymentAccounts()->with('branch')
                ->when(! $request->user()->isOrgWide(), fn ($q) => $q->availableForBranch($request->user()->branch_id))
                ->get()->map(fn ($a) => [
                'id' => $a->id,
                'branch_id' => $a->branch_id,
                'branch_name' => $a->branch?->name,
                'editable' => $request->user()->isOrgWide() || $a->branch_id === $request->user()->branch_id,
                'type' => $a->type,
                'bank_key' => $a->bank_key,
                'display_name' => $a->display_name,
                'account_number' => $a->account_number,
                'account_name' => $a->account_name,
                'logo_url' => $a->logoUrl(),
            ]),
            'bankPresets' => collect(PaymentAccount::PRESETS)->map(fn ($p, $key) => [
                'key' => $key,
                'label' => $p['label'],
                'type' => $p['type'],
                'logo_url' => asset("images/banks/{$key}.png"),
            ])->values(),
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
            'phone' => ['nullable', 'string', 'max:50', new \App\Rules\PhoneNumberRule],
            'email' => ['nullable', 'email', 'max:255'],
            'calendar_preference' => ['required', 'in:ethiopian,gregorian,both'],
            'missing_mark_policy' => ['required', 'in:exclude,zero'],
            'discount_limit_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'waiver_limit_amount' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'payment_due_days' => ['required', 'integer', 'min:0', 'max:365'],
            'purchase_approval_threshold' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'allow_overlapping_terms' => ['required', 'boolean'],
            'enabled_languages' => ['required', 'array', 'min:1'],
            'enabled_languages.*' => ['string', 'in:en,am,om,ti'],
        ]);

        $request->user()->school->update($validated);

        return back()->with('success', 'School settings updated.');
    }
}
