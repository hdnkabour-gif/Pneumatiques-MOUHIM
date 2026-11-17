<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| Uploaded images (logo, used tyre photos, expense photos) are stored in storage/app/public and
| served as /storage/... through the public/storage link. The Docker image does not contain that
| link: without it, the URL generated for a logo uploaded through the company settings form
| answered 403 (checked with a real upload), so the images were not displayed.
*/

beforeEach(function () {
    $this->link = public_path('storage');
    $this->hadLink = is_link($this->link);
    if (file_exists($this->link) && ! $this->hadLink) {
        $this->markTestSkipped('public/storage is a real folder on this machine.');
    }
});

afterEach(function () {
    if (! $this->hadLink && is_link($this->link)) {
        unlink($this->link);
    }
});

it('creates the public storage link at every start of the web container, before the server starts', function () {
    $script = file_get_contents(base_path('docker/entrypoint.sh'));
    $web = substr($script, strpos($script, '    web)'), strpos($script, '    scheduler)') - strpos($script, '    web)'));

    expect($web)->toContain('php artisan storage:link --force')
        ->and(strpos($web, 'storage:link --force'))->toBeLessThan(strpos($web, 'frankenphp php-server'));
});

it('serves an uploaded file at the URL the application generates, after every restart', function () {
    if (is_link($this->link)) {
        unlink($this->link);
    }
    $path = 'company/logo-'.uniqid().'.jpg';
    Storage::disk('public')->put($path, 'JPEG-BYTES');

    try {
        // Two container starts: the second one finds the link already there.
        foreach ([1, 2] as $start) {
            expect(Artisan::call('storage:link', ['--force' => true]))->toBe(0)
                ->and(Artisan::output())->not->toContain('ERROR');
        }

        $url = Storage::disk('public')->url($path);
        $served = public_path(ltrim(parse_url($url, PHP_URL_PATH), '/'));

        expect(parse_url($url, PHP_URL_PATH))->toBe('/storage/'.$path)
            ->and(is_link($this->link))->toBeTrue()
            ->and(file_get_contents($served))->toBe('JPEG-BYTES');
    } finally {
        Storage::disk('public')->delete($path);
    }
});

it('never deletes a real folder that stands where the link should be', function () {
    if (is_link($this->link)) {
        unlink($this->link);
    }
    File::ensureDirectoryExists($this->link);
    file_put_contents($this->link.'/keep.txt', 'KEEP');

    try {
        Artisan::call('storage:link', ['--force' => true]);

        expect(is_link($this->link))->toBeFalse()
            ->and(file_get_contents($this->link.'/keep.txt'))->toBe('KEEP');
    } finally {
        File::deleteDirectory($this->link);
    }
});
