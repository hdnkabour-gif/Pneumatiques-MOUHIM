<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The database WAS restored, but the uploaded files were not all put back. The archive is
 * unchanged: putting its files in place again (--files-only) finishes the restore. The command
 * given repeats the disk and the folder actually used, so it reads the same archive and writes
 * into the same place.
 */
class PartialRestoreException extends RuntimeException
{
    public function __construct(
        public readonly string $disk,
        public readonly string $archive,
        public readonly string $target,
        public readonly int $placed,
        public readonly int $total,
        Throwable $previous,
    ) {
        parent::__construct(
            "The database WAS restored, but only {$placed} of {$total} uploaded files were put into [{$target}]: {$previous->getMessage()} "
            .'Finish with: '.$this->finishCommand().' (repeatable; it uses the unchanged archive).',
            previous: $previous,
        );
    }

    public function finishCommand(): string
    {
        return 'php artisan app:backup-restore '.escapeshellarg($this->archive).' --files-only'
            .' --disk='.escapeshellarg($this->disk).' --files-to='.escapeshellarg($this->target);
    }
}
