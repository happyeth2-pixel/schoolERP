<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AccountInvitation;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public "Set your password" page reached from an invitation link. Unknown,
 * used, revoked and expired links all get the same page, so it reveals nothing
 * about which accounts exist.
 */
class InvitationController extends Controller
{
    public function show(string $token): Response
    {
        $invitation = AccountInvitation::findUsable($token);

        if (! $invitation) {
            return Inertia::render('Auth/AcceptInvitation', ['valid' => false]);
        }

        return Inertia::render('Auth/AcceptInvitation', [
            'valid' => true,
            'token' => $token,
            'name' => $invitation->user->name,
            'email' => $invitation->user->email,
            'school' => $invitation->user->school?->name,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $invitation = AccountInvitation::findUsable($token);
        $user = $invitation?->accept($validated['password'], (string) $request->ip());

        if (! $user) {
            return redirect()->route('invitation.show', $token);
        }

        AuditLog::record($user, 'account.invitation_accepted', $user, null, null, 'from IP '.$request->ip());

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Your password is set. Welcome!');
    }
}
