<?php

namespace App\Models;

use App\Enums\SiteType;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Site extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'code', 'name', 'type', 'has_workshop', 'sales_enabled',
        'address', 'city', 'phone', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => SiteType::class,
            'has_workshop' => 'boolean',
            'sales_enabled' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /** Sites the given user is allowed to see. */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->canSeeAllSites()) {
            return;
        }

        $query->whereIn($query->qualifyColumn('id'), $user->siteIds());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }
}
