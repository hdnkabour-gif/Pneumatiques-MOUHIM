<?php

namespace App\Actions\Users;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Creates or updates a user with their role and assigned sites, enforcing
 * the account rules that must hold whatever the interface does.
 */
class SaveUserAction
{
    /**
     * @param  array{name: string, email: string, phone?: ?string, password?: ?string, locale?: string,
     *     is_active?: bool, role: string, sites?: list<int>, default_site_id?: ?int}  $data
     */
    public function execute(?User $user, array $data, User $actor): User
    {
        $role = Role::findByName($data['role'], 'web');
        $siteIds = array_map('intval', $data['sites'] ?? []);
        $defaultSiteId = isset($data['default_site_id']) ? (int) $data['default_site_id'] : null;
        $isActive = (bool) ($data['is_active'] ?? true);

        if ($role->name === RoleName::Owner->value && ! $actor->isOwner()) {
            throw ValidationException::withMessages(['role' => __('app.errors.forbidden')]);
        }

        if ($defaultSiteId !== null && ! in_array($defaultSiteId, $siteIds, true)) {
            throw ValidationException::withMessages(['default_site_id' => __('users.errors.default_site_not_assigned')]);
        }

        if ($user !== null && $user->is($actor) && ! $isActive) {
            throw ValidationException::withMessages(['is_active' => __('users.errors.cannot_disable_self')]);
        }

        return DB::transaction(function () use ($user, $data, $role, $siteIds, $defaultSiteId, $isActive) {
            $attributes = Arr::only($data, ['name', 'email', 'phone', 'locale']);
            $attributes['is_active'] = $isActive;
            $attributes['default_site_id'] = $defaultSiteId ?? ($siteIds[0] ?? null);

            if (filled($data['password'] ?? null)) {
                $attributes['password'] = $data['password'];
            }

            $user ??= new User;
            $wasOwner = $user->exists && $user->isOwner();
            $user->fill($attributes);
            if (filled($data['approval_pin'] ?? null) && $actor->isOwner()) {
                $user->approval_pin = Hash::make((string) $data['approval_pin']);
            }
            $user->save();
            $user->syncRoles([$role->name]);
            $user->sites()->sync($siteIds);

            if ($wasOwner && ($role->name !== RoleName::Owner->value || ! $isActive)) {
                $this->assertAnActiveOwnerRemains();
            }

            return $user->refresh();
        });
    }

    private function assertAnActiveOwnerRemains(): void
    {
        $owners = User::role(RoleName::Owner->value)->where('is_active', true)->lockForUpdate()->pluck('id')->count();

        if ($owners === 0) {
            throw ValidationException::withMessages(['role' => __('users.errors.last_owner')]);
        }
    }
}
