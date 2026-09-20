<?php

use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The app's name and tagline come from one place each (APP_NAME and APP_TAGLINE),
 * so a new app built from the template can be renamed without touching the code.
 * These tests keep it that way, and keep the Laravel starter kit's own branding
 * from creeping back in.
 */

test('the home page is given the configured app name and tagline', function () {
    config(['app.name' => 'Acme Tracker', 'app.tagline' => 'Track everything.']);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('name', 'Acme Tracker')
            ->where('tagline', 'Track everything.'));
});

test('the page title is the configured app name', function () {
    config(['app.name' => 'Acme Tracker']);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Acme Tracker', false)
        ->assertDontSee('<title>Laravel</title>', false);
});

test('the sign in and register pages load', function () {
    $this->get(route('login'))->assertOk();
    $this->get(route('register'))->assertOk();
});

test('the app carries no Laravel starter kit branding', function () {
    $forbidden = [
        'laravel.com',
        'laracasts',
        'rsms.me',
        'laravel/vue-starter-kit',
        "|| 'Laravel'",
        "config('app.name', 'Laravel')",
    ];

    // Hand-written front end code only: the generated route files and the
    // shadcn/ui components are not part of the app's own branding.
    $generated = ['/js/actions/', '/js/routes/', '/js/wayfinder/', '/components/ui/'];

    $found = collect([resource_path('js'), resource_path('views')])
        ->flatMap(fn (string $directory) => File::allFiles($directory))
        ->reject(fn ($file) => collect($generated)->contains(fn ($part) => str_contains(str_replace('\\', '/', $file->getPathname()), $part)))
        ->flatMap(function ($file) use ($forbidden) {
            $contents = File::get($file->getPathname());

            return collect($forbidden)
                ->filter(fn (string $needle) => str_contains($contents, $needle))
                ->map(fn (string $needle) => "{$file->getRelativePathname()} contains \"{$needle}\"");
        })
        ->values()
        ->all();

    expect($found)->toBe([]);
});
