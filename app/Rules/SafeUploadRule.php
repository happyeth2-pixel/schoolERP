<?php

namespace App\Rules;

use App\Support\SafeUpload;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/** Validates an upload by its CONTENT (see SafeUpload), whatever it is called. */
class SafeUploadRule implements ValidationRule
{
    /** @param list<string> $allowed  any of jpg, png, pdf */
    public function __construct(private array $allowed) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('Upload a file.');

            return;
        }

        if ($problem = SafeUpload::problem($value, $this->allowed)) {
            $fail($problem);
        }
    }
}
