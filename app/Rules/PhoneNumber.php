<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\GhanaPhone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Form-request wrapper around `GhanaPhone`. One regex, in one place, used by both. */
final class PhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! GhanaPhone::isValid($value)) {
            $fail('Enter a Ghanaian mobile number, like 024 123 4567.');
        }
    }
}
