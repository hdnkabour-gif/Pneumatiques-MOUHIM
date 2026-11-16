<?php

namespace App\Console\Commands;

use App\Actions\Backups\RestoreDatabaseBackupAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[Signature('app:backup-restore
    {path? : Backup path on the disk (latest when omitted)}
    {--disk= : Backup disk}
    {--into= : Restore into this NEW database; the current database is not touched (recommended)}
    {--replace-current : Replace ALL data of the current database instead}
    {--files-only : Only put the uploaded files of the archive in place (finishes a partial restore)}
    {--files-to= : Folder for the uploaded files (with --into: a new private folder)}
    {--without-files : Restore the database only}
    {--force : Skip the confirmation (refused for --replace-current in production)}')]
#[Description('Restore a backup archive into a new database (recommended) or over the current one')]
class RestoreBackupCommand extends Command
{
    public function handle(RestoreDatabaseBackupAction $restore): int
    {
        $into = $this->option('into');
        $replace = (bool) $this->option('replace-current');
        $filesOnly = (bool) $this->option('files-only');

        if ((int) filled($into) + (int) $replace + (int) $filesOnly !== 1) {
            $this->error('Choose one: --into=<new_database> (recommended, the current database is not touched), --replace-current or --files-only.');

            return self::FAILURE;
        }

        $disk = $this->option('disk') ?? config('mouhim.backup.disk');
        $backups = $restore->available($disk);

        if ($backups === []) {
            $this->error("No backup found on disk [{$disk}].");

            return self::FAILURE;
        }

        $path = $this->argument('path')
            ?? ($this->option('force') || ! $this->input->isInteractive() ? $backups[0] : select('Backup to restore', $backups, $backups[0]));
        $withFiles = ! $this->option('without-files');
        $filesTo = $this->option('files-to');

        if ($filesOnly) {
            $target = $filesTo ?? storage_path('app/public');

            // Live files are replaced: in production a person always confirms.
            if (! $this->option('force') || app()->isProduction()) {
                if (! $this->input->isInteractive()) {
                    $this->error('Putting files in place needs an interactive confirmation.');

                    return self::FAILURE;
                }
                if (! confirm("Put the uploaded files of [{$path}] into [{$target}]? Files there with the same name are replaced; none is deleted.", false)) {
                    $this->error('Not confirmed: nothing was changed.');

                    return self::FAILURE;
                }
            }

            $restore->filesOnly($disk, $path, $filesTo);
            $this->info("Uploaded files of [{$path}] put into [{$target}]. The database was not changed.");

            return self::SUCCESS;
        }

        if ($replace) {
            $database = DB::connection()->getConfig('database');

            // In production, replacing the data always needs the database name typed by a person.
            if (! $this->option('force') || app()->isProduction()) {
                if (! $this->input->isInteractive()) {
                    $this->error('Replacing the current database needs an interactive confirmation.');

                    return self::FAILURE;
                }

                $typed = text("Replace ALL data of database [{$database}] with [{$path}]? Type the database name to confirm");
                if ($typed !== $database) {
                    $this->error('Not confirmed: nothing was changed.');

                    return self::FAILURE;
                }
            }

            $restore->replacingCurrent($disk, $path, withFiles: $withFiles, filesTarget: $filesTo);
            $this->info("Database [{$database}] restored from [{$path}].");

            return self::SUCCESS;
        }

        $restore->intoNewDatabase($disk, $path, $into, withFiles: $withFiles, filesTarget: $filesTo);
        $this->info("Backup [{$path}] restored into the new database [{$into}]. The current database was not changed.");
        if ($withFiles) {
            $this->info('Files restored into the private folder ['.($filesTo ?? RestoreDatabaseBackupAction::restoredFilesRoot().'/'.$into).'] (the live files were not changed).');
        }

        return self::SUCCESS;
    }
}
