<?php

namespace App\Console\Commands;

use App\Enums\RoleName;
use App\Models\Site;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * The only way to create the first owner account: there is no default
 * account in any seeder (CLAUDE.md §7).
 */
#[Signature('app:create-owner {--name=} {--email=} {--password= : Avoid in shell history; prompted when omitted} {--force : Create even if an owner already exists}')]
#[Description('Create an owner (full access) account')]
class CreateOwnerCommand extends Command
{
    public function handle(): int
    {
        if (! Role::query()->where('name', RoleName::Owner->value)->exists()) {
            $this->call('db:seed', ['--class' => 'RolesAndPermissionsSeeder', '--force' => true]);
        }

        if (! $this->option('force') && User::role(RoleName::Owner->value)->exists()) {
            $this->error(__('users.commands.owner_exists'));

            return self::FAILURE;
        }

        $data = [
            'name' => $this->option('name') ?? text(__('users.fields.name'), required: true),
            'email' => $this->option('email') ?? text(__('users.fields.email'), required: true),
            'password' => $this->option('password') ?? password(__('users.fields.password'), required: true),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::min(12)->letters()->numbers()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($data) {
            $user = User::query()->create([...$data, 'locale' => config('mouhim.default_locale')]);
            $user->assignRole(RoleName::Owner->value);
            $user->sites()->sync(Site::query()->pluck('id'));

            return $user;
        });

        $this->info(__('users.commands.owner_created', ['email' => $user->email]));

        return self::SUCCESS;
    }
}
