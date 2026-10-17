<?php

use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

/*
 * deploy/lib.sh compares tool versions so the setup command can stop early when
 * one is too old. Versions are compared part by part as numbers, never as text.
 */

function versionAtLeast(string $found, string $needed): bool
{
    $script = 'source '.escapeshellarg(base_path('deploy/lib.sh')).'; version_at_least "$1" "$2"';

    return Process::run([(new ExecutableFinder)->find('bash'), '-c', $script, 'bash', $found, $needed])->successful();
}

test('a version is at least another when it is equal or newer', function (string $found, string $needed) {
    expect(versionAtLeast($found, $needed))->toBeTrue();
})->with([
    'equal' => ['18.3.0', '18.3.0'],
    'a newer patch' => ['18.3.1', '18.3.0'],
    'a newer minor' => ['18.4.0', '18.3.0'],
    'a newer major' => ['22.16.0', '18.3.0'],
    'a leading v' => ['v22.16.0', '18.3.0'],
    'a leading v on both' => ['v18.3.0', 'v18.3.0'],
    'two digit parts are numbers, not text' => ['2.100.0', '2.79.0'],
    'two digit minor' => ['18.10.0', '18.3.0'],
    'missing parts count as zero' => ['2.79', '2.79.0'],
    'more parts than needed are ignored' => ['2.79.0.5', '2.79.0'],
    'a leading zero is not read as octal' => ['2.08.0', '2.8.0'],
]);

test('a version is not at least another when it is older', function (string $found, string $needed) {
    expect(versionAtLeast($found, $needed))->toBeFalse();
})->with([
    'an older patch' => ['18.2.9', '18.3.0'],
    'an older minor' => ['18.2.0', '18.3.0'],
    'an older major' => ['16.20.2', '18.3.0'],
    'text order would say older, numbers say newer is needed' => ['2.9.0', '2.25.0'],
    'missing parts count as zero' => ['18', '18.3.0'],
    'a leading v' => ['v18.2.0', '18.3.0'],
]);
