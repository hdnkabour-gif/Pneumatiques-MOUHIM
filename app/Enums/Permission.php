<?php

namespace App\Enums;

/**
 * Every permission checked server-side. New phases add cases here, then
 * `php artisan db:seed --class=RolesAndPermissionsSeeder` syncs them.
 */
enum Permission: string
{
    // Sites
    case SitesView = 'sites.view';
    case SitesManage = 'sites.manage';
    case SitesViewAll = 'sites.view_all';

    // Users & roles
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';
    case RolesManage = 'roles.manage';

    // Settings
    case SettingsManage = 'settings.manage';

    // Activity log
    case ActivityLogView = 'activity_log.view';

    public function label(): string
    {
        return __('permissions.'.$this->value);
    }

    public function group(): string
    {
        return __('permissions.groups.'.explode('.', $this->value)[0]);
    }
}
