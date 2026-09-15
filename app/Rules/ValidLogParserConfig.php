<?php

namespace App\Rules;

use App\Support\LogParserConfig;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidLogParserConfig implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach (LogParserConfig::errors($value) as $error) {
            $fail($error);
        }
    }
}
