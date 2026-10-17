<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/*
 * The Dependabot configuration decides which updates arrive together, and the problems
 * with it have been about versions that have to move as a pair arriving apart. These tests
 * keep the fixes in place.
 */

function dependabotEcosystem(string $name): array
{
    $config = Yaml::parse(File::get(base_path('.github/dependabot.yml')));

    return collect($config['updates'])->firstWhere('package-ecosystem', $name);
}

test('laravel/wayfinder is never held back by the wait, so it is as new as the framework', function () {
    $cooldown = dependabotEcosystem('composer')['cooldown'];

    // The framework can be proposed with a Wayfinder that does not know its newest routes
    // (framework 13.35 with Wayfinder 0.1.21 fails the type check) if both are held back.
    expect($cooldown['exclude'] ?? [])->toContain('laravel/wayfinder');
});

test('the other PHP packages keep the wait, which protects against a bad release', function () {
    $cooldown = dependabotEcosystem('composer')['cooldown'];

    expect($cooldown['default-days'])->toBeGreaterThanOrEqual(1)
        ->and($cooldown['exclude'] ?? [])->not->toContain('*')
        ->and($cooldown['exclude'] ?? [])->not->toContain('laravel/framework');
});

test('the PHP packages still arrive as one group, so the framework and Wayfinder are in one pull request', function () {
    $groups = dependabotEcosystem('composer')['groups'];

    expect($groups['composer']['patterns'])->toBe(['*']);
});

test('the npm and GitHub Actions updates keep their wait', function (string $ecosystem) {
    expect(dependabotEcosystem($ecosystem)['cooldown']['default-days'])->toBeGreaterThanOrEqual(1);
})->with(['npm', 'github-actions']);

test('vite-plus and its vite alias are left to be updated by hand, and TypeScript stays below 7', function () {
    $ignored = collect(dependabotEcosystem('npm')['ignore']);

    expect($ignored->pluck('dependency-name')->all())->toContain('vite-plus', 'vite', 'typescript')
        ->and($ignored->firstWhere('dependency-name', 'typescript')['update-types'])->toBe(['version-update:semver-major']);
});
