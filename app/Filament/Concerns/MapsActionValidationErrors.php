<?php

namespace App\Filament\Concerns;

use Closure;
use Illuminate\Validation\ValidationException;

/**
 * Business actions report errors by field name ("role"); Filament forms
 * expect them under the form state path ("data.role").
 */
trait MapsActionValidationErrors
{
    protected function runAction(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn (array $messages, string $key) => ["data.{$key}" => $messages])->all(),
            );
        }
    }
}
