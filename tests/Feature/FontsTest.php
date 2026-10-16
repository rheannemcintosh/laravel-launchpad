<?php

use Illuminate\Support\Facades\File;

/*
 * The app's font is built into the app, so a build downloads nothing and the pages
 * ask no other website for a font. A download from a font website timing out has
 * failed a real deploy before, and the setup command builds the first image, so
 * these tests keep it that way.
 */

test('the font comes from an installed package, not from a font website', function () {
    $config = File::get(base_path('vite.config.ts'));

    expect($config)
        ->toContain("import { fontsource } from 'laravel-vite-plugin/fonts';")
        ->toContain("fontsource('Instrument Sans'")
        ->not->toContain('bunny(')
        ->not->toContain('google(');

    $package = json_decode(File::get(base_path('package.json')), true);

    expect($package['devDependencies'])->toHaveKey('@fontsource/instrument-sans');
});

test('no hand-written page, style or script names a font website', function () {
    $generated = ['/js/actions/', '/js/routes/', '/js/wayfinder/'];

    $found = collect([resource_path('js'), resource_path('css'), resource_path('views')])
        ->flatMap(fn (string $directory) => File::allFiles($directory))
        ->reject(fn ($file) => collect($generated)->contains(fn ($part) => str_contains(str_replace('\\', '/', $file->getPathname()), $part)))
        ->filter(fn ($file) => preg_match('#fonts\.(googleapis|gstatic|bunny)\.#i', File::get($file->getPathname())) === 1)
        ->map(fn ($file) => $file->getRelativePathname())
        ->values()
        ->all();

    expect($found)->toBe([]);
});

test('the built font files are served from the app and none is fetched from elsewhere', function () {
    $manifest = public_path('build/fonts-manifest.json');

    if (! File::exists($manifest)) {
        $this->markTestSkipped('The front end has not been built.');
    }

    $contents = File::get($manifest);

    expect($contents)->not->toMatch('#https?://#')
        ->and($contents)->toContain('instrument-sans-400-normal')
        ->and($contents)->toContain('instrument-sans-500-normal')
        ->and($contents)->toContain('instrument-sans-600-normal');
});
