<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Validation\ValidationException;

/**
 * A business rule refused the operation. The message is already translated
 * and written for a non-technical user (CLAUDE.md §5).
 */
class BusinessRuleException extends DomainException
{
    public static function make(string $translationKey, array $replace = []): static
    {
        return new static(__($translationKey, $replace));
    }

    public function toValidationException(string $field = 'data'): ValidationException
    {
        return ValidationException::withMessages([$field => $this->getMessage()]);
    }
}
