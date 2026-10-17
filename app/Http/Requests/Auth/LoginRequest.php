<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Security\LoginThrottle;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Sign the person in, or say no -- always in the same words.
     *
     * The person types a phone number in any everyday form (0911..., 0711..., +251911...); accounts that have only an
     * email keep signing in with it until an administrator gives them a phone number. A wrong password, an unknown
     * number, a switched-off account and something that is not a number are indistinguishable from outside, and are
     * all counted the same way by LoginThrottle (layered: per account, per known device, per network address, burst).
     * While a wait is running the password is not checked at all. Enforced entirely on the server.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $throttle = app(LoginThrottle::class);
        $identifier = trim($this->string('phone')->toString());
        $key = LoginThrottle::keyFor($identifier);
        $known = User::findForLogin($identifier);

        $wait = $throttle->wait($this, $key, $known?->id);
        if ($wait > 0) {
            $throttle->blocked($this, $key, $known, $identifier, $wait);

            throw ValidationException::withMessages(['phone' => $this->waitMessage($wait)]);
        }
        $throttle->attempting($this, $key);

        $credentials = $this->credentials($identifier);

        if ($credentials === null || ! Auth::attempt([...$credentials, 'password' => $this->string('password')->toString(), 'status' => 'active'], $this->boolean('remember'))) {
            $throttle->failed($this, $key, $known, $identifier);

            throw ValidationException::withMessages(['phone' => trans('auth.failed')]);
        }

        // There is no student portal yet (a deliberate product decision): a "student" role account
        // is refused rather than dropped into a half-built area. Families use a parent login.
        if (Auth::user()->hasRole('student')) {
            Auth::logout();
            $throttle->failed($this, $key, $known, $identifier);

            throw ValidationException::withMessages(['phone' => trans('auth.failed')]);
        }

        $throttle->succeeded($this, $key, Auth::user(), $identifier);
        Auth::user()->forceFill(['last_login_at' => now()])->saveQuietly();
    }

    /** "Please wait 45 seconds" / "1 minute": how long, without saying why or how many tries are left. */
    private function waitMessage(int $seconds): string
    {
        $time = $seconds < 90
            ? trans_choice('auth.time_seconds', $seconds)
            : trans_choice('auth.time_minutes', (int) ceil($seconds / 60));

        return trans('auth.throttle', ['time' => $time]);
    }

    /** @return array<string, string>|null the lookup column and value, or null when the input cannot be a phone number or email */
    private function credentials(string $identifier): ?array
    {
        if (str_contains($identifier, '@')) {
            return ['email' => mb_strtolower($identifier)];
        }

        $canonical = PhoneNumber::normalizeMobile($identifier);

        return $canonical ? ['login_phone' => $canonical] : null;
    }
}
