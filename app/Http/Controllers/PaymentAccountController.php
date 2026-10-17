<?php

namespace App\Http\Controllers;

use App\Models\PaymentAccount;
use App\Rules\SafeUploadRule;
use App\Support\SafeUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PaymentAccountController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->user()->can('manage-school-settings') || abort(403);

        $validated = $request->validate([
            'bank_key' => ['nullable', 'string', Rule::in(array_keys(PaymentAccount::PRESETS))],
            'display_name' => ['required_without:bank_key', 'nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:100'],
            'account_name' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', 'file', 'max:2048', new SafeUploadRule(['jpg', 'png'])],
            'branch_id' => ['nullable', 'integer'],
        ]);

        // NULL = every branch. Only school-wide (org-level) accounts may add school-wide
        // accounts or choose a branch; a branch admin's accounts always belong to their own branch.
        $user = $request->user();
        $branchId = $validated['branch_id'] ?? null;
        if (! $user->isOrgWide()) {
            $branchId = $user->branch_id;
        } elseif ($branchId !== null) {
            $user->school->branches()->whereKey($branchId)->firstOrFail();
        }

        $bankKey = $validated['bank_key'] ?? null;
        $preset = $bankKey ? PaymentAccount::PRESETS[$bankKey] : null;

        $logoPath = null;
        if ($request->hasFile('logo')) {
            $logoPath = SafeUpload::storePublicImage($request->file('logo'), 'payment-logos', 256);
        }

        PaymentAccount::create([
            'school_id' => $request->user()->school_id,
            'branch_id' => $branchId,
            'type' => $preset['type'] ?? 'bank',
            'bank_key' => $bankKey,
            'display_name' => $preset['label'] ?? $validated['display_name'],
            'account_number' => $validated['account_number'] ?? null,
            'account_name' => $validated['account_name'] ?? null,
            'logo_path' => $logoPath,
            'sort_order' => $request->user()->school->paymentAccounts()->count(),
        ]);

        return back()->with('success', 'Payment account added.');
    }

    public function destroy(Request $request, PaymentAccount $paymentAccount): RedirectResponse
    {
        $request->user()->can('manage-school-settings') || abort(403);
        abort_unless($paymentAccount->school_id === $request->user()->school_id, 403);
        // a branch admin may only remove their own branch's accounts, not school-wide ones or another branch's
        abort_unless($request->user()->isOrgWide() || $paymentAccount->branch_id === $request->user()->branch_id, 403);

        // Archived, not erased: payments already made to it keep pointing at a real account
        // (and its logo file stays, so old receipts still render).
        $paymentAccount->delete();

        return back()->with('success', 'Payment account removed from the list parents see. Past payments are unaffected.');
    }
}
