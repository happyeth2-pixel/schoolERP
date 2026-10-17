<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PhoneNumberRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== null && $value !== '' && ! PhoneNumber::isValid((string) $value)) {
            $fail('Enter a valid phone number, for example 0911 22 33 44 or +251 911 22 33 44.');
        }
    }
}
