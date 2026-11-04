<?php

namespace App\Console\Commands;

use App\Actions\Backups\RestoreDatabaseBackupAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

#[Signature('app:backup-restore {path? : Backup path on the disk (latest when omitted)} {--disk= : Backup disk} {--force : Skip the confirmation}')]
#[Description('Restore the database from a backup archive (replaces ALL current data)')]
class RestoreBackupCommand extends Command
{
    public function handle(RestoreDatabaseBackupAction $restore): int
    {
        $disk = $this->option('disk') ?? config('mouhim.backup.disk');
        $backups = $restore->available($disk);

        if ($backups === []) {
            $this->error("No backup found on disk [{$disk}].");

            return self::FAILURE;
        }

        $path = $this->argument('path')
            ?? ($this->option('force') ? $backups[0] : select('Backup to restore', $backups, $backups[0]));

        if (! $this->option('force') && ! confirm("Replace ALL data of the database with [{$path}]?", false)) {
            return self::FAILURE;
        }

        $restore->execute($disk, $path);

        $this->info("Database restored from [{$path}].");

        return self::SUCCESS;
    }
}
