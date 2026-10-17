<?php

/*
 * Shared by the stand-ins for az, gh and curl that the deploy script tests put on
 * the PATH. They keep what Azure and GitHub would remember in one JSON file, so a
 * first run, a re-run and a teardown all see consistent results, and they log every
 * call. A command or query they do not know stops with exit code 97 and a message,
 * so a change to a script that outgrows them fails a test instead of passing quietly.
 */

function fake_dir(): string
{
    $dir = getenv('FAKE_STATE');

    if ($dir === false || $dir === '') {
        fwrite(STDERR, "fake: FAKE_STATE is not set\n");
        exit(98);
    }

    return $dir;
}

function fake_defaults(): array
{
    return [
        'account' => [
            'logged_in' => true,
            'id' => '00000000-0000-4000-8000-000000000001',
            'tenant' => '00000000-0000-4000-8000-000000000002',
            'user' => 'me@example.com',
            'user_object_id' => '00000000-0000-4000-8000-0000000000aa',
        ],
        'providers' => [],
        'groups' => [],
        'sql_servers' => [],
        'sql_dbs' => [],
        'envs' => [],
        'apps' => [],
        'budgets' => [],
        'ad_apps' => [],
        'sps' => [],
        'roles' => [],
        'extra_resources' => [],
        'extensions' => [],
        'restarts' => [],
        'deleted_apps' => [],
        'role_create_failures' => 0,
        'curl_not_yet' => 0,
        'counters' => [],
        'github' => ['secrets' => [], 'variables' => [], 'oidc_prefix' => ''],
    ];
}

function fake_load(): array
{
    $path = fake_dir().'/state.json';
    $state = is_file($path) ? json_decode(file_get_contents($path), true) : [];

    return array_replace_recursive(fake_defaults(), $state);
}

function fake_save(array $state): void
{
    file_put_contents(fake_dir().'/state.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function fake_log(string $line): void
{
    file_put_contents(fake_dir().'/calls.log', $line."\n", FILE_APPEND);
}

function fake_fail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message."\n");
    exit($code);
}

function fake_unsupported(string $what): never
{
    fwrite(STDERR, "fake: unsupported {$what}. The script changed in a way the stand-in does not know yet.\n");
    exit(97);
}

/** FAKE_FAIL is a comma separated list of call prefixes that should fail, such as "az rest put,gh secret set". */
function fake_should_fail(string $call): bool
{
    foreach (array_filter(array_map('trim', explode(',', (string) getenv('FAKE_FAIL')))) as $prefix) {
        if (str_starts_with($call, $prefix)) {
            return true;
        }
    }

    return false;
}

function fake_next(array &$state, string $kind): int
{
    $state['counters'][$kind] = ($state['counters'][$kind] ?? 0) + 1;

    return $state['counters'][$kind];
}

function fake_guid(int $family, int $n): string
{
    return sprintf('%08d-0000-4000-8000-%012d', $family, $n);
}

/** The value after the first of $names, or null. */
function fake_opt(array $args, array $names): ?string
{
    foreach ($args as $i => $arg) {
        if (in_array($arg, $names, true)) {
            return $args[$i + 1] ?? '';
        }
    }

    return null;
}

/** Every value after $name, up to the next option. */
function fake_multi(array $args, string $name): array
{
    $values = [];
    $found = false;

    foreach ($args as $arg) {
        if ($arg === $name) {
            $found = true;

            continue;
        }

        if ($found) {
            if (str_starts_with($arg, '--')) {
                break;
            }

            $values[] = $arg;
        }
    }

    return $values;
}

/** "A=1" pairs into an array. Values keep any spaces and equals signs. */
function fake_pairs(array $items): array
{
    $pairs = [];

    foreach ($items as $item) {
        $position = strpos($item, '=');

        if ($position !== false) {
            $pairs[substr($item, 0, $position)] = substr($item, $position + 1);
        }
    }

    return $pairs;
}
