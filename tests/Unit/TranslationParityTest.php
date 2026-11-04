<?php

use App\Enums\Permission;
use App\Enums\RoleName;

/*
| No interface text is hard-coded (CLAUDE.md §5): every key must exist in
| both French and Arabic, and no translation may be left empty.
*/

function translationKeys(array $lines, string $prefix = ''): array
{
    $keys = [];
    foreach ($lines as $key => $value) {
        $full = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
        is_array($value) ? array_push($keys, ...translationKeys($value, $full)) : $keys[] = $full;
    }

    return $keys;
}

function langDir(string $path = ''): string
{
    return dirname(__DIR__, 2).'/lang/'.$path;
}

function translationFiles(string $locale): array
{
    return collect(glob(langDir("{$locale}/*.php")))->map(fn ($f) => basename($f))->sort()->values()->all();
}

it('has the same translation files in French and Arabic', function () {
    expect(translationFiles('ar'))->toBe(translationFiles('fr'))->not->toBeEmpty();
});

it('has the same keys in French and Arabic', function (string $file) {
    $fr = translationKeys(require langDir("fr/{$file}"));
    $ar = translationKeys(require langDir("ar/{$file}"));

    expect(array_values(array_diff($fr, $ar)))->toBe([], "Missing in ar/{$file}")
        ->and(array_values(array_diff($ar, $fr)))->toBe([], "Missing in fr/{$file}");
})->with(fn () => translationFiles('fr'));

it('has no empty translation', function (string $locale) {
    foreach (translationFiles($locale) as $file) {
        $lines = require langDir("{$locale}/{$file}");
        array_walk_recursive($lines, function ($value, $key) use ($locale, $file) {
            expect(is_string($value) ? trim($value) : $value)->not->toBe('', "{$locale}/{$file}: {$key}");
        });
    }
})->with(['fr', 'ar']);

it('translates every role and permission', function (string $locale) {
    app()->setLocale($locale);

    foreach (RoleName::cases() as $role) {
        expect($role->label())->not->toStartWith('roles.');
    }
    foreach (Permission::cases() as $permission) {
        expect($permission->label())->not->toStartWith('permissions.')
            ->and($permission->group())->not->toStartWith('permissions.');
    }
})->with(['fr', 'ar']);
