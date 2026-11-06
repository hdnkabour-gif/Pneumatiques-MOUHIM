<?php

namespace App\Exceptions;

use App\Enums\ApprovalType;

/**
 * The operation is allowed only after the owner's approval. The screen
 * offers to send the request or to type the owner's PIN on the spot.
 */
class ApprovalRequiredException extends BusinessRuleException
{
    /** @var list<ApprovalType> */
    public array $types = [];

    /** @param  list<ApprovalType>  $types */
    public static function for(array $types): static
    {
        $e = static::make('sales.errors.approval_required', [
            'types' => implode(', ', array_map(fn (ApprovalType $t) => $t->label(), $types)),
        ]);
        $e->types = $types;

        return $e;
    }
}
