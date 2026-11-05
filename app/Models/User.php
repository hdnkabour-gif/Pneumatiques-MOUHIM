<?php

namespace App\Models;

use App\Enums\Locale;
use App\Enums\Permission;
use App\Enums\RoleName;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'password', 'locale', 'default_site_id', 'is_active'])]
#[Hidden(['password', 'remember_token', 'approval_pin'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, LogsActivity, Notifiable;

    /** Known defaults so a freshly created user has every two-factor attribute (strict mode). */
    protected $attributes = [
        'app_authentication_secret' => null,
        'app_authentication_recovery_codes' => null,
    ];

    /** @var list<int>|null */
    private ?array $siteIdsCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'locale' => Locale::class,
            'is_active' => 'boolean',
        ];
    }

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class);
    }

    public function defaultSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'default_site_id');
    }

    public function isOwner(): bool
    {
        return $this->hasRole(RoleName::Owner->value);
    }

    public function canSeeAllSites(): bool
    {
        return $this->isOwner() || $this->hasPermissionTo(Permission::SitesViewAll->value);
    }

    /** @return list<int> */
    public function siteIds(): array
    {
        return $this->siteIdsCache ??= $this->sites()->pluck('sites.id')->map(fn ($id) => (int) $id)->all();
    }

    public function canAccessSite(Site|int $site): bool
    {
        $siteId = $site instanceof Site ? $site->getKey() : $site;

        return $this->canSeeAllSites() || in_array($siteId, $this->siteIds(), true);
    }

    /** Two-factor authentication is required for owners only (§19, decision D-47). */
    public function requiresTwoFactor(): bool
    {
        return $this->isOwner();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'phone', 'locale', 'default_site_id', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
