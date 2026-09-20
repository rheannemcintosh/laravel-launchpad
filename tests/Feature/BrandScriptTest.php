<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

/*
 * `npm run brand` gives a new app its own name, tagline and icon. These tests run
 * the real script against a scratch copy of the files it edits, never the app's own.
 */

const BRAND_FILES = [
    '.env.example',
    'resources/js/components/AppLogoIcon.vue',
    'public/favicon.svg',
    'public/favicon.ico',
    'public/apple-touch-icon.png',
];

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/brand-'.uniqid();

    foreach (BRAND_FILES as $file) {
        File::ensureDirectoryExists(dirname("{$this->root}/{$file}"));
        File::copy(base_path($file), "{$this->root}/{$file}");
    }

    // The app's own .env is never touched: the scratch one starts from the example.
    File::copy(base_path('.env.example'), "{$this->root}/.env");
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function runBrand(string $root, array $arguments, array $env = [])
{
    $node = (new ExecutableFinder)->find('node');

    return Process::env($env)
        ->run([$node, base_path('scripts/brand.mjs'), '--root', $root, ...$arguments]);
}

function brandSnapshot(string $root): array
{
    return collect([...BRAND_FILES, '.env'])
        ->mapWithKeys(fn (string $file) => [$file => File::get("{$root}/{$file}")])
        ->all();
}

test('the name and tagline are written to .env and .env.example', function () {
    $result = runBrand($this->root, ['--name', 'Acme Tracker', '--tagline', 'Track everything.']);

    expect($result->successful())->toBeTrue($result->errorOutput());

    foreach (['.env', '.env.example'] as $file) {
        $contents = File::get("{$this->root}/{$file}");

        expect($contents)
            ->toContain('APP_NAME="Acme Tracker"')
            ->toContain('APP_TAGLINE="Track everything."')
            ->toContain('APP_ENV=local');
    }
});

test('a line that .env does not have yet is added', function () {
    File::put("{$this->root}/.env", "APP_NAME=Old\nAPP_ENV=local");

    runBrand($this->root, ['--tagline', 'Track everything.']);

    expect(File::get("{$this->root}/.env"))
        ->toBe("APP_NAME=Old\nAPP_ENV=local\nAPP_TAGLINE=\"Track everything.\"\n");
});

test('the icon is swapped in the component and the browser tab icon', function () {
    $result = runBrand($this->root, ['--icon', 'heart-pulse']);

    expect($result->successful())->toBeTrue($result->errorOutput());

    $component = File::get("{$this->root}/resources/js/components/AppLogoIcon.vue");

    expect($component)
        ->toContain("import { HeartPulse } from '@lucide/vue';")
        ->toContain('<HeartPulse :class="className"')
        ->not->toContain('Rocket');

    $favicon = File::get("{$this->root}/public/favicon.svg");

    expect($favicon)
        ->toContain('M2 9.5a5.5 5.5 0 0 1 9.591-3.676')
        ->not->toContain('M12 15v5s3.03');
});

test('an icon can be given in PascalCase as well', function () {
    runBrand($this->root, ['--icon', 'HeartPulse']);

    expect(File::get("{$this->root}/resources/js/components/AppLogoIcon.vue"))
        ->toContain("import { HeartPulse } from '@lucide/vue';");
});

test('running it again with the same values changes nothing', function () {
    $arguments = ['--name', 'Acme Tracker', '--tagline', 'Track everything.', '--icon', 'heart-pulse'];

    runBrand($this->root, $arguments);
    $before = brandSnapshot($this->root);

    $second = runBrand($this->root, $arguments);

    expect($second->successful())->toBeTrue()
        ->and($second->output())->not->toContain('Updated')
        ->and(brandSnapshot($this->root))->toBe($before);
});

test('the values can be changed again later', function () {
    runBrand($this->root, ['--name', 'First', '--icon', 'heart-pulse']);
    runBrand($this->root, ['--name', 'Second', '--icon', 'brain']);

    expect(File::get("{$this->root}/.env.example"))->toContain('APP_NAME="Second"')
        ->and(File::get("{$this->root}/resources/js/components/AppLogoIcon.vue"))
        ->toContain('import { Brain }')
        ->not->toContain('HeartPulse');
});

test('an unknown icon stops the script and changes nothing, even the name', function () {
    $before = brandSnapshot($this->root);

    $result = runBrand($this->root, ['--name', 'Acme Tracker', '--icon', 'not-a-real-icon']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('"not-a-real-icon" is not a Lucide icon')
        ->and(brandSnapshot($this->root))->toBe($before);
});

test('running it with nothing to set stops with the usage', function () {
    $before = brandSnapshot($this->root);

    $result = runBrand($this->root, []);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('Give at least one of --name, --tagline or --icon')
        ->and($result->errorOutput())->toContain('Usage:')
        ->and(brandSnapshot($this->root))->toBe($before);
});

test('a name that would break the .env file is refused', function (string $name) {
    $before = brandSnapshot($this->root);

    $result = runBrand($this->root, ['--name', $name]);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('--name cannot contain')
        ->and(brandSnapshot($this->root))->toBe($before);
})->with([
    'a double quote' => ['Acme "Tracker"'],
    'a dollar sign' => ['Acme $Tracker'],
    'a backslash' => ['Acme\\Tracker'],
]);

test('a blank name is refused', function () {
    $result = runBrand($this->root, ['--name', '   ']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('--name cannot be empty');
});

test('the picture icons are rebuilt when an image converter is installed', function () {
    runBrand($this->root, ['--icon', 'heart-pulse']);

    $ico = File::get("{$this->root}/public/favicon.ico");
    $apple = File::get("{$this->root}/public/apple-touch-icon.png");

    // An .ico file starts 00 00 01 00, and holds three images. A PNG starts with its signature.
    expect(bin2hex(substr($ico, 0, 6)))->toBe('000001000300')
        ->and(substr($apple, 1, 3))->toBe('PNG')
        ->and($ico)->not->toBe(File::get(base_path('public/favicon.ico')));
})->skip(fn () => (new ExecutableFinder)->find('rsvg-convert') === null, 'rsvg-convert is not installed');

test('the picture icons are left alone, with a note, when there is no image converter', function () {
    $emptyPath = "{$this->root}/empty-path";
    File::ensureDirectoryExists($emptyPath);
    $before = File::get("{$this->root}/public/favicon.ico");

    $result = runBrand($this->root, ['--icon', 'heart-pulse'], ['PATH' => $emptyPath]);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('left as they were')
        ->and(File::get("{$this->root}/public/favicon.ico"))->toBe($before)
        ->and(File::get("{$this->root}/public/favicon.svg"))->toContain('M2 9.5a5.5');
});
