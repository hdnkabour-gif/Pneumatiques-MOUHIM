<?php

namespace App\Support;

/**
 * Tells whether this process runs in the isolated PNEU MOUHIM Staging project on Railway.
 *
 * The check does not rely on APP_ENV (Staging runs with APP_ENV=production) nor on the Laravel
 * configuration (env() returns nothing once the configuration is cached, and a configuration
 * value could be copied to production). It reads the variables that Railway itself sets in the
 * process: the project ID, fixed below, and the environment name. The shop's project has a
 * different ID, so it is refused whatever its settings.
 */
class StagingGuard
{
    /** "PNEU MOUHIM - STAGING" project on Railway (created 17/11/2026). */
    public const PROJECT_ID = 'da9818fd-7480-4e37-b0e0-bcd8359564b8';

    public const ENVIRONMENT = 'staging';

    /** Null when allowed, otherwise the reason of the refusal. */
    public static function refusal(): ?string
    {
        $project = (string) getenv('RAILWAY_PROJECT_ID');
        $environment = (string) getenv('RAILWAY_ENVIRONMENT_NAME');

        if ($project !== self::PROJECT_ID) {
            return 'This is not the PNEU MOUHIM Staging project on Railway (RAILWAY_PROJECT_ID '.($project === '' ? 'is missing' : 'differs').').';
        }
        if ($environment !== self::ENVIRONMENT) {
            return "This is not the \"staging\" environment (RAILWAY_ENVIRONMENT_NAME is \"{$environment}\").";
        }

        return null;
    }
}
