<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** An Ethiopian mobile number in any everyday form: 09..., 07..., +2519..., +2517.... */
class EthiopianMobileRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== null && $value !== '' && PhoneNumber::normalizeMobile((string) $value) === null) {
            $fail('Enter an Ethiopian mobile number that starts with 09 or 07, for example 0911 22 33 44.');
        }
    }
}
