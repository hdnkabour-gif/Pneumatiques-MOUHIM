<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The database WAS restored, but the uploaded files could not all be put back.
 * The verified copy of the files is kept, so they can be moved back without the archive.
 */
class PartialRestoreException extends RuntimeException
{
    public function __construct(public readonly string $stagedFiles, public readonly string $target, Throwable $previous)
    {
        parent::__construct(
            "The database WAS restored, but the uploaded files were not all moved into [{$target}]: {$previous->getMessage()} "
            ."A complete, verified copy of the files is kept in [{$stagedFiles}]: copy its content into [{$target}] "
            ."(cp -a {$stagedFiles}/. {$target}/), then delete it.",
            previous: $previous,
        );
    }
}
