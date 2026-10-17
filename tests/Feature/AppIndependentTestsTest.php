<?php

use Illuminate\Support\Facades\File;

/*
 * An app made from the template changes its icon, its README and its names, and its tests
 * run on GitHub the moment it is created, so a test that expects the template's own icon or
 * README fails the very first run of every app that chose differently. Tests that need
 * those files write their own, as the setup command's tests do.
 */

test('no test reads the icon files or the README that an app changes', function () {
    $forbidden = [
        "base_path('resources/js/components/AppLogoIcon.vue')",
        "base_path('public/favicon",
        "base_path('public/apple-touch-icon",
        "base_path('README.md')",
    ];

    $found = collect(File::allFiles(base_path('tests')))
        ->reject(fn ($file) => $file->getFilename() === 'AppIndependentTestsTest.php')
        ->filter(fn ($file) => $file->getExtension() === 'php')
        ->flatMap(function ($file) use ($forbidden) {
            $contents = File::get($file->getPathname());

            return collect($forbidden)
                ->filter(fn (string $needle) => str_contains($contents, $needle))
                ->map(fn (string $needle) => "{$file->getRelativePathname()} reads {$needle}");
        })
        ->values()
        ->all();

    expect($found)->toBe([]);
});
