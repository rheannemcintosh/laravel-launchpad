<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

/*
 * The deployed app shows the name and tagline set with `npm run brand`, which
 * writes them to .env.example. These tests check how deploy/lib.sh decides them,
 * which is shared by the provisioning script and the bootstrap script.
 */

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/display-'.uniqid();

    File::ensureDirectoryExists($this->root);
    Process::path($this->root)->run('git init -q -b main')->throw();
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function displayValues(object $test, array $env = []): array
{
    $script = 'source '.escapeshellarg(base_path('deploy/lib.sh')).'; APP_NAME=laravel-launchpad; resolve_app_display; printf "%s\n%s" "$APP_TITLE" "$APP_TAGLINE"';

    $output = Process::path($test->root)
        ->env(['APP_TITLE' => false, 'APP_TAGLINE' => false, ...$env])
        ->run([(new ExecutableFinder)->find('bash'), '-c', $script])
        ->throw()
        ->output();

    [$title, $tagline] = array_pad(explode("\n", $output, 2), 2, '');

    return [$title, $tagline];
}

test('the name and tagline come from .env.example, without their quotes', function () {
    File::put("{$this->root}/.env.example", "APP_NAME=\"Acme Tracker\"\nAPP_TAGLINE=\"Track everything, quietly.\"\n");

    expect(displayValues($this))->toBe(['Acme Tracker', 'Track everything, quietly.']);
});

test('unquoted values are read too', function () {
    File::put("{$this->root}/.env.example", "APP_NAME=Plain Name\nAPP_TAGLINE=Plain tagline\n");

    expect(displayValues($this))->toBe(['Plain Name', 'Plain tagline']);
});

test('the template placeholder name falls back to the repository name in title case', function () {
    File::put("{$this->root}/.env.example", "APP_NAME=\"My App\"\nAPP_TAGLINE=\"A tagline.\"\n");

    expect(displayValues($this))->toBe(['Laravel Launchpad', 'A tagline.']);
});

test('without .env.example it falls back to the repository name and no tagline', function () {
    expect(displayValues($this))->toBe(['Laravel Launchpad', '']);
});

test('without a name line it falls back to the repository name', function () {
    File::put("{$this->root}/.env.example", "APP_ENV=local\n");

    expect(displayValues($this))->toBe(['Laravel Launchpad', '']);
});

test('explicit APP_TITLE and APP_TAGLINE settings win', function () {
    File::put("{$this->root}/.env.example", "APP_NAME=\"Acme Tracker\"\nAPP_TAGLINE=\"From the file.\"\n");

    expect(displayValues($this, ['APP_TITLE' => 'From The Setting', 'APP_TAGLINE' => 'From the setting.']))
        ->toBe(['From The Setting', 'From the setting.']);
});

test('the template ships a placeholder that falls back, so an unbranded app is named after its repository', function () {
    $template = File::get(base_path('.env.example'));

    expect($template)->toContain('APP_NAME="My App"');
});
