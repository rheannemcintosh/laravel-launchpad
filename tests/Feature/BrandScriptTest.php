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
    'composer.json',
    'composer.lock',
    'README.md',
];

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/brand-'.uniqid();

    foreach (BRAND_FILES as $file) {
        File::ensureDirectoryExists(dirname("{$this->root}/{$file}"));
        File::copy(base_path($file), "{$this->root}/{$file}");
    }

    // A README of its own, so these tests do not depend on what an app made from the template
    // has done to the real one.
    File::put("{$this->root}/README.md", "# Laravel Launchpad 🚀\n\nIntro prose.\n\n```bash\ngit clone git@github.com:rheannemcintosh/laravel-launchpad.git\n```\n");

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
        ->and($result->errorOutput())->toContain('Give at least one of --name, --tagline, --icon or --package')
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

// ---------------------------------------------------------------------------
// --package: the Composer package name and the README

function composerBinary(): ?string
{
    return (new ExecutableFinder)->find('composer');
}

function composerValidates(string $directory): bool
{
    return Process::path($directory)
        ->env(['COMPOSER_HOME' => "{$directory}/.composer-home"])
        ->run([composerBinary(), 'validate', '--no-check-publish', '--no-interaction'])
        ->successful();
}

function lockHash(string $root): string
{
    preg_match('/"content-hash": "([0-9a-f]{32})"/', File::get("{$root}/composer.lock"), $match);

    return $match[1];
}

test('--package renames the Composer package and changes nothing else in composer.json', function () {
    $before = File::get("{$this->root}/composer.json");
    $current = json_decode($before, true)['name'];

    $result = runBrand($this->root, ['--package', 'acme/brand-test']);
    $after = File::get("{$this->root}/composer.json");

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($after)->toContain('"name": "acme/brand-test"')
        ->and($after)->toBe(str_replace("\"name\": \"{$current}\"", '"name": "acme/brand-test"', $before));
});

test('--package brings the lock file fingerprint in step, which Composer accepts', function () {
    $before = lockHash($this->root);

    runBrand($this->root, ['--package', 'brand-test/fingerprint']);

    expect(lockHash($this->root))->toMatch('/^[0-9a-f]{32}$/')->not->toBe($before);
    expect(File::get("{$this->root}/composer.lock"))->toBe(preg_replace('/"content-hash": "[0-9a-f]{32}"/', '"content-hash": "'.lockHash($this->root).'"', File::get(base_path('composer.lock'))));
})->group('composer');

test('Composer says the renamed lock file is up to date, and says a rename by hand leaves it out of date', function () {
    expect(composerValidates($this->root))->toBeTrue();

    runBrand($this->root, ['--package', 'acme/acme-tracker']);

    expect(composerValidates($this->root))->toBeTrue();

    // The control: the name changed by hand without the fingerprint is not up to date.
    File::put("{$this->root}/composer.json", str_replace('acme/acme-tracker', 'acme/changed-by-hand', File::get("{$this->root}/composer.json")));

    expect(composerValidates($this->root))->toBeFalse();
})->skip(fn () => composerBinary() === null, 'Composer is not installed');

test('the fingerprint matches Composer for text it encodes carefully: non-ASCII, slashes and empty objects', function () {
    File::put("{$this->root}/composer.json", json_encode([
        'name' => 'laravel/vue-starter-kit',
        'description' => 'Not part of the fingerprint.',
        'keywords' => ['a/b', 'ß'],
        'require' => new stdClass,
        // Only some keys are part of the fingerprint, and extra is one of them.
        'extra' => [
            'note' => "Ünï/cödé 🚀 \"quoted\" back\\slash\ttab\u{7f}",
            'empty' => new stdClass,
            'list' => ['a/b', 'ß', 1, 2.5, true, null],
        ],
        'config' => ['platform' => ['php' => '8.4.0'], 'sort-packages' => true],
        'minimum-stability' => 'stable',
        'prefer-stable' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    File::delete("{$this->root}/composer.lock");

    $made = Process::path($this->root)
        ->env(['COMPOSER_HOME' => "{$this->root}/.composer-home"])
        ->run([composerBinary(), 'update', '--no-interaction', '--no-install', '--no-scripts', '--no-plugins']);

    expect($made->successful())->toBeTrue($made->errorOutput().$made->output())
        ->and(composerValidates($this->root))->toBeTrue();

    $result = runBrand($this->root, ['--package', 'acme/acme-tracker']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(composerValidates($this->root))->toBeTrue();
})->skip(fn () => composerBinary() === null, 'Composer is not installed');

test('--name with --package gives the README the name as its title', function () {
    runBrand($this->root, ['--name', 'Acme Tracker', '--package', 'acme/acme-tracker']);

    $readme = File::get("{$this->root}/README.md");

    expect(explode("\n", $readme)[0])->toBe('# Acme Tracker')
        ->and($readme)->toContain('Intro prose.')
        ->and($readme)->not->toContain('# Laravel Launchpad');
});

test('the template name in example commands is replaced inside code blocks only', function () {
    File::put("{$this->root}/README.md", <<<'MD'
        # Laravel Launchpad 🚀

        Laravel Launchpad is described here, and laravel-launchpad is mentioned in prose.

        ```bash
        git clone git@github.com:rheannemcintosh/laravel-launchpad.git
        cd laravel-launchpad
        ```

        1. Build it:

            ```bash
            docker build -t laravel-launchpad .
            ```

        After the code, laravel-launchpad is prose again.
        MD);

    runBrand($this->root, ['--name', 'Acme Tracker', '--package', 'acme/acme-tracker']);

    expect(File::get("{$this->root}/README.md"))->toBe(<<<'MD'
        # Acme Tracker

        Laravel Launchpad is described here, and laravel-launchpad is mentioned in prose.

        ```bash
        git clone git@github.com:acme/acme-tracker.git
        cd acme-tracker
        ```

        1. Build it:

            ```bash
            docker build -t acme-tracker .
            ```

        After the code, laravel-launchpad is prose again.
        MD);
});

test('--package without --name renames the package and the examples but leaves the README title', function () {
    runBrand($this->root, ['--package', 'acme/acme-tracker']);

    $readme = File::get("{$this->root}/README.md");

    expect(explode("\n", $readme)[0])->toBe('# Laravel Launchpad 🚀')
        ->and($readme)->toContain('git clone git@github.com:acme/acme-tracker.git');
});

test('running it again with the same package, name and tagline changes nothing', function () {
    $arguments = ['--name', 'Acme Tracker', '--tagline', 'Track.', '--package', 'acme/acme-tracker'];

    runBrand($this->root, $arguments);
    $before = brandSnapshot($this->root);

    $second = runBrand($this->root, $arguments);

    expect($second->successful())->toBeTrue($second->errorOutput())
        ->and($second->output())->not->toContain('Updated')
        ->and(brandSnapshot($this->root))->toBe($before);
});

test('a different name and package later update the package, the title and the fingerprint', function () {
    runBrand($this->root, ['--name', 'First', '--package', 'acme/first']);
    $first = lockHash($this->root);

    runBrand($this->root, ['--name', 'Second', '--package', 'acme/second']);

    expect(File::get("{$this->root}/composer.json"))->toContain('"name": "acme/second"')
        ->and(explode("\n", File::get("{$this->root}/README.md"))[0])->toBe('# Second')
        ->and(lockHash($this->root))->not->toBe($first);

    if (composerBinary() !== null) {
        expect(composerValidates($this->root))->toBeTrue();
    }
});

test('without --package, composer.json, composer.lock and the README are never touched', function () {
    $before = brandSnapshot($this->root);

    runBrand($this->root, ['--name', 'Acme Tracker', '--tagline', 'Track.', '--icon', 'brain']);
    $after = brandSnapshot($this->root);

    foreach (['composer.json', 'composer.lock', 'README.md'] as $file) {
        expect($after[$file])->toBe($before[$file]);
    }
});

test('a package name Composer would not accept is refused before anything is changed', function (string $package) {
    $before = brandSnapshot($this->root);

    // A leading dash would be read as another option, so the value is joined with an equals sign.
    $result = runBrand($this->root, ['--name', 'Acme Tracker', "--package={$package}"]);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('is not a Composer package name')
        ->and(brandSnapshot($this->root))->toBe($before);
})->with([
    'uppercase' => ['Acme/Tracker'],
    'no vendor' => ['tracker'],
    'an empty name' => ['acme/'],
    'an empty vendor' => ['/tracker'],
    'a space' => ['acme/acme tracker'],
    'two slashes' => ['acme/acme/tracker'],
    'a shell character' => ['acme/tracker;rm'],
    'a leading dash' => ['-acme/tracker'],
    'two dots in a row' => ['acme/tra..cker'],
]);

test('package names with dots, underscores, numbers and hyphens are accepted', function (string $package) {
    $result = runBrand($this->root, ['--package', $package]);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(File::get("{$this->root}/composer.json"))->toContain("\"name\": \"{$package}\"");
})->with(['acme/tracker.v2', 'my_org/my_app', 'a1/b2', 'acme/my-app', 'my-org/my-app']);

test('a lock file that does not match composer.json is refused with nothing changed', function () {
    File::put("{$this->root}/composer.lock", preg_replace('/"content-hash": "[0-9a-f]{32}"/', '"content-hash": "00000000000000000000000000000000"', File::get("{$this->root}/composer.lock")));
    $before = brandSnapshot($this->root);

    $result = runBrand($this->root, ['--name', 'Acme Tracker', '--package', 'acme/acme-tracker']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('composer.lock does not match composer.json')
        ->and($result->errorOutput())->toContain('composer update --lock')
        ->and(brandSnapshot($this->root))->toBe($before);
});

test('without a lock file only composer.json and the README are renamed, with a note', function () {
    File::delete("{$this->root}/composer.lock");

    $result = runBrand($this->root, ['--name', 'Acme Tracker', '--package', 'acme/acme-tracker']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('composer.lock does not exist')
        ->and(File::get("{$this->root}/composer.json"))->toContain('"name": "acme/acme-tracker"')
        ->and(File::exists("{$this->root}/composer.lock"))->toBeFalse();
});

test('without a README the package is still renamed', function () {
    File::delete("{$this->root}/README.md");

    $result = runBrand($this->root, ['--name', 'Acme Tracker', '--package', 'acme/acme-tracker']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(File::get("{$this->root}/composer.json"))->toContain('"name": "acme/acme-tracker"')
        ->and(File::exists("{$this->root}/README.md"))->toBeFalse();
});

test('a composer.json that is not valid JSON stops it with nothing changed', function () {
    File::put("{$this->root}/composer.json", '{ not json');
    $before = brandSnapshot($this->root);

    $result = runBrand($this->root, ['--name', 'Acme Tracker', '--package', 'acme/acme-tracker']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('not valid JSON')
        ->and(brandSnapshot($this->root))->toBe($before);
});

test('an unknown icon stops everything, including the package rename', function () {
    $before = brandSnapshot($this->root);

    $result = runBrand($this->root, ['--icon', 'not-a-real-icon', '--package', 'acme/acme-tracker']);

    expect($result->failed())->toBeTrue()
        ->and(brandSnapshot($this->root))->toBe($before);
});
