<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SecurityEvent;
use App\Rules\NotBasedOnIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** The first-sign-in screen: a temporary password must be replaced before anything else can be done. */
class RequiredPasswordChangeController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/ChangeTemporaryPassword', ['name' => $request->user()->name]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->must_change_password) {
            return redirect()->route('dashboard');
        }

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::defaults(), NotBasedOnIdentity::forUser($user)],
        ]);

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ])->save();

        AuditLog::record($user, 'account.password_changed', $user, null, null, 'Temporary password replaced by the person themself');
        SecurityEvent::log('password_changed', $user, $user->login_phone ?? $user->email, null, ['kind' => 'temporary_replaced']);

        // any other place this account was signed in with the temporary password is signed out
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        }
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Your new password is saved.');
    }
}
