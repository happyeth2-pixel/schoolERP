<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects a password that trivially contains (or is contained by) one of the account's own
 * identifying details -- name, phone, email. Laravel's own Password::defaults() has no
 * equivalent check, and nothing in this app enforced one, so a password of e.g. "0911223344"
 * (the account's own phone) or "SelamBekele1" (the account's own name) passed every existing
 * rule. Deliberately a small, separate rule rather than folded into Password::defaults(),
 * since it needs to know WHO the password belongs to, which that static rule set does not.
 */
class NotBasedOnIdentity implements ValidationRule
{
    /** @var list<string> */
    private array $identityValues;

    public function __construct(?string ...$identity)
    {
        $this->identityValues = $this->expand($identity);
    }

    public static function forUser(?User $user): self
    {
        return $user
            ? new self($user->name, $user->email, $user->phone, $user->login_phone)
            : new self();
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $password = mb_strtolower((string) $value);

        foreach ($this->identityValues as $identity) {
            if (str_contains($password, $identity) || str_contains($identity, $password)) {
                $fail('Choose a password that is not based on your name, phone number, or email.');

                return;
            }
        }
    }

    /** @param array<int, string|null> $raw @return list<string> */
    private function expand(array $raw): array
    {
        $values = [];

        foreach (array_filter($raw) as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $values[] = $value;

            // A phone typed "+251 911 22 33 44" should still catch a password of "0911223344".
            $digits = preg_replace('/\D+/', '', $value);
            if ($digits !== null && $digits !== '') {
                $values[] = $digits;
            }

            if (str_contains($value, '@')) {
                $values[] = strstr($value, '@', true) ?: $value;
            }
        }

        $values = array_map(fn ($v) => mb_strtolower($v), $values);

        // Anything shorter than 4 characters (a single initial, a 2-digit area code fragment)
        // is too short to be a meaningful signal and would just produce false-positive rejections.
        return array_values(array_unique(array_filter($values, fn ($v) => mb_strlen($v) >= 4)));
    }
}
