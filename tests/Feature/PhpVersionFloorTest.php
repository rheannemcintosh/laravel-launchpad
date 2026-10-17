<?php

use Illuminate\Support\Facades\File;

/*
 * Dependabot works out the PHP version to resolve updates against from the PHP requirement
 * in composer.json, taking the lowest version it allows. If a locked package needs a newer
 * PHP than that, every Dependabot run for the PHP packages fails and no update is ever
 * proposed. Updating packages on a newer PHP can quietly bring that about, so this checks
 * that the lowest PHP version composer.json allows is one every locked package accepts.
 */

/** The version, padded to three parts: 8.4 becomes 8.4.0. */
function phpFloorNormalise(string $version): string
{
    $parts = array_pad(explode('.', ltrim($version, 'vV=')), 3, '0');

    return implode('.', array_slice($parts, 0, 3));
}

/** The lowest version one alternative of a constraint allows, such as ^8.4.1 or >=8.4.1 <8.6. */
function phpFloorLowest(string $constraint): ?string
{
    foreach (preg_split('/[\s,]+/', trim($constraint)) as $clause) {
        if (preg_match('/^(?:\^|~|>=|=)?v?(\d+(?:\.\d+){0,2})(?:\.\*)?$/', $clause, $match)) {
            return phpFloorNormalise($match[1]);
        }
    }

    return null;
}

/** Whether $version satisfies one clause. A clause this does not understand counts as satisfied. */
function phpFloorClauseAllows(string $version, string $clause): bool
{
    if (! preg_match('/^(\^|~|>=|<=|>|<|!=|=)?v?(\d+(?:\.\d+){0,2})(\.\*)?$/', $clause, $m)) {
        return true;
    }

    [$operator, $given, $wild] = [$m[1] ?? '', $m[2], $m[3] ?? ''];
    $target = phpFloorNormalise($given);
    $segments = count(explode('.', $given));

    if ($wild !== '') {
        return str_starts_with($version.'.', $given.'.');
    }

    return match ($operator) {
        '^' => version_compare($version, $target, '>=') && version_compare($version, ((int) explode('.', $target)[0] + 1).'.0.0', '<'),
        '~' => version_compare($version, $target, '>=') && version_compare($version, $segments >= 3
            ? explode('.', $target)[0].'.'.((int) explode('.', $target)[1] + 1).'.0'
            : ((int) explode('.', $target)[0] + 1).'.0.0', '<'),
        '>=' => version_compare($version, $target, '>='),
        '<=' => version_compare($version, $target, '<='),
        '>' => version_compare($version, $target, '>'),
        '<' => version_compare($version, $target, '<'),
        '!=' => version_compare($version, $target, '!='),
        default => version_compare($version, $target, '=='),
    };
}

/** A hyphen range such as 8.1 - 8.5 as two clauses. A partial upper end allows everything inside it. */
function phpFloorExpandRanges(string $constraint): string
{
    return preg_replace_callback('/(\d+(?:\.\d+){0,2})\s+-\s+(\d+(?:\.\d+){0,2})/', function (array $m) {
        $upper = explode('.', $m[2]);

        if (count($upper) < 3) {
            $upper[count($upper) - 1] = (int) $upper[count($upper) - 1] + 1;

            return ">={$m[1]} <".phpFloorNormalise(implode('.', $upper));
        }

        return ">={$m[1]} <={$m[2]}";
    }, $constraint);
}

function phpFloorSatisfies(string $version, string $constraint): bool
{
    foreach (preg_split('/\s*\|\|?\s*/', phpFloorExpandRanges(trim($constraint))) as $alternative) {
        $clauses = array_filter(preg_split('/[\s,]+/', trim($alternative)));

        if (collect($clauses)->every(fn (string $clause) => phpFloorClauseAllows($version, $clause))) {
            return true;
        }
    }

    return false;
}

test('the PHP version composer.json allows at the lowest is one every locked package accepts', function () {
    $composer = json_decode(File::get(base_path('composer.json')), true);
    $lock = json_decode(File::get(base_path('composer.lock')), true);

    $floor = collect(preg_split('/\s*\|\|?\s*/', $composer['require']['php']))
        ->map(fn (string $alternative) => phpFloorLowest($alternative))
        ->filter()
        ->sort(fn (string $a, string $b) => version_compare($a, $b))
        ->first();

    expect($floor)->not->toBeNull();

    $refused = collect([...$lock['packages'], ...$lock['packages-dev']])
        ->filter(fn (array $package) => isset($package['require']['php']))
        ->reject(fn (array $package) => phpFloorSatisfies($floor, $package['require']['php']))
        ->map(fn (array $package) => "{$package['name']} {$package['version']} needs PHP {$package['require']['php']}")
        ->values()
        ->all();

    expect($refused)->toBe([], "composer.json allows PHP {$floor}, which these locked packages do not accept. Raise the PHP requirement in composer.json to the version they need, refresh composer.lock, and Dependabot will work again.");
});

test('the checker itself understands the constraints packages use', function (string $version, string $constraint, bool $expected) {
    expect(phpFloorSatisfies($version, $constraint))->toBe($expected);
})->with([
    ['8.4.1', '>=8.4.1', true],
    ['8.4.0', '>=8.4.1', false],
    ['8.4.0', '^8.4', true],
    ['8.4.0', '^8.4.1', false],
    ['8.4.1', '^8.4.1', true],
    ['8.5.0', '^8.4.1', true],
    ['9.0.0', '^8.4.1', false],
    ['8.4.1', '~8.4.0', true],
    ['8.5.0', '~8.4.0', false],
    ['8.5.0', '~8.4', true],
    ['8.4.3', '8.4.*', true],
    ['8.5.0', '8.4.*', false],
    ['9.0.0', '^8.2 || ^8.3', false],
    ['8.4.1', '8.1 - 8.5', true],
    ['8.5.9', '8.1 - 8.5', true],
    ['8.6.0', '8.1 - 8.5', false],
    ['8.0.9', '8.1 - 8.5', false],
    ['8.4.1', '8.2.1 - 8.4.5', true],
    ['8.4.6', '8.2.1 - 8.4.5', false],
    ['8.3.9', '^8.2 || ^8.3', true],
    ['8.4.1', '>=8.2 <8.6', true],
    ['8.6.0', '>=8.2 <8.6', false],
    ['8.4.1', '>=8.2,<8.6', true],
    ['8.4.1', '>=8.2|>=9', true],
    ['8.4.1', 'something unexpected', true],
]);
