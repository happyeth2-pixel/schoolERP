<?php

namespace App\Casts;

use App\Support\PhoneNumber;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Normalizes on the way in, so whatever the source (form, import, seeder, tinker)
 * the database only ever holds the canonical +251... form. Text that is not a
 * phone number at all is kept as typed rather than silently thrown away;
 * validation (EthiopianPhone rule) is what rejects it at the forms.
 */
class Phone implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return PhoneNumber::normalize((string) $value) ?? trim((string) $value);
    }
}
