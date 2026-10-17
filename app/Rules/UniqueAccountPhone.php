<?php

namespace App\Rules;

use App\Models\User;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * "This number is already someone's": compared AFTER normalising, so 0911223344, +251911223344 and 251 911 22 33 44
 * are one number. It looks at sign-in numbers and contact numbers, so an account cannot be made for a number that
 * already belongs to another person's account.
 */
class UniqueAccountPhone implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreUserId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $canonical = PhoneNumber::normalizeMobile((string) $value);
        if ($canonical === null) {
            return;   // the mobile rule reports that
        }

        $owner = User::query()
            ->where(fn ($q) => $q->where('login_phone', $canonical)->orWhere('phone', $canonical))
            ->when($this->ignoreUserId, fn ($q) => $q->where('id', '!=', $this->ignoreUserId))
            ->first(['id', 'name']);

        if ($owner) {
            $fail('This phone number already belongs to another account. Check the number, or find that person in the staff or parent list.');
        }
    }
}
