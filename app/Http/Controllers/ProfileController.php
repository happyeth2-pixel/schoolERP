<?php

namespace App\Http\Controllers;

use App\Support\SafeUpload;
use App\Rules\SafeUploadRule;
use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Update the user's own profile picture. Any account tier can do
     * this for themselves -- there's no admin approval step, since a
     * profile photo carries no permission implications.
     */
    public function updatePhoto(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'photo' => ['required', 'file', 'max:2048', new SafeUploadRule(['jpg', 'png'])],
        ]);

        $user = $request->user();

        if ($user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
        }

        $path = SafeUpload::storePublicImage($validated['photo'], 'avatars', 512);
        $user->update(['profile_photo_path' => $path]);

        return back()->with('success', 'Profile photo updated.');
    }

    /**
     * A user's own display language, chosen from whichever the school
     * has enabled -- never an arbitrary code, so nobody can land on a
     * language the school hasn't actually turned on (or reviewed).
     */
    public function updateLocale(Request $request): RedirectResponse
    {
        $user = $request->user();
        $enabled = $user->school->enabled_languages ?? ['en'];

        $validated = $request->validate([
            'locale' => ['required', 'string', 'in:'.implode(',', $enabled)],
        ]);

        $user->update(['locale' => $validated['locale']]);

        return back();
    }

    /**
     * Deactivate the user's own account (records are kept).
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // Accounts own history (marks entered, payments verified, audit entries), so
        // they are deactivated, never deleted -- and the last active owner cannot lock the school out.
        if ($user->hasRole('owner') && User::where('school_id', $user->school_id)->where('status', 'active')->whereHas('roles', fn ($q) => $q->where('name', 'owner'))->count() <= 1) {
            return back()->withErrors(['password' => 'You are the only active owner of this school. Add another owner before deactivating your account.']);
        }

        $user->update(['status' => 'inactive']);
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
