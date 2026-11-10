<?php

namespace App\Providers;

use App\Listeners\LogAuthenticationEvents;
use App\Models\Activity;
use App\Models\Site;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Policies\RolePolicy;
use App\Services\Push\PushNotifier;
use App\Services\SettingsService;
use AsyncAws\S3\S3Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;
use Spatie\Activitylog\Support\PendingActivityLog;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Railway serves the app over HTTPS: every generated link (assets, signed WhatsApp links) must say https.
        if ($this->app->isProduction() && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);

        Event::subscribe(LogAuthenticationEvents::class);

        // Every in-app notification also goes to the user's phones (Web Push), when configured.
        Event::listen(NotificationSent::class, function ($event) {
            if ($event->channel !== 'database' || ! $event->notifiable instanceof User) {
                return;
            }
            $data = method_exists($event->notification, 'toDatabase') ? (array) $event->notification->toDatabase($event->notifiable) : [];
            if (filled($data['title'] ?? null)) {
                app(PushNotifier::class)->send($event->notifiable, (string) $data['title'], $data['body'] ?? null);
            }
        });

        $this->configureActivityLog();
        $this->registerS3CompatibleDriver();
    }

    /** Every journal entry records the site context and IP address. */
    private function configureActivityLog(): void
    {
        PendingActivityLog::beforeLogging(function (Activity $activity) {
            if ($activity->getAttribute('site_id') === null) {
                $activity->site_id = $this->resolveActivitySiteId($activity);
            }

            if ($activity->getAttribute('ip_address') === null) {
                $activity->ip_address = request()->ip();
            }
        });
    }

    private function resolveActivitySiteId(Activity $activity): ?int
    {
        $subject = $activity->subject;

        if ($subject instanceof Site) {
            return $subject->getKey();
        }

        if ($subject !== null && array_key_exists('site_id', $subject->getAttributes())) {
            return $subject->getAttribute('site_id');
        }

        return auth()->user()?->default_site_id;
    }

    /**
     * Lightweight S3-compatible driver (Cloudflare R2, Backblaze B2, Scaleway…)
     * used for off-Railway backups.
     */
    private function registerS3CompatibleDriver(): void
    {
        Storage::extend('s3-compatible', function ($app, array $config) {
            $client = new S3Client(array_filter([
                'region' => $config['region'] ?? 'auto',
                'endpoint' => $config['endpoint'] ?? null,
                'accessKeyId' => $config['key'] ?? null,
                'accessKeySecret' => $config['secret'] ?? null,
                'pathStyleEndpoint' => ($config['use_path_style_endpoint'] ?? true) ? 'true' : 'false',
            ]));

            $adapter = new AsyncAwsS3Adapter($client, $config['bucket'], $config['root'] ?? '');

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });
    }
}
