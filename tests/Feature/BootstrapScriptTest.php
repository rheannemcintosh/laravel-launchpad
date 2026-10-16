<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

/*
 * deploy/bootstrap.sh takes a new app from the template to Azure by running the
 * other scripts in order. These tests run the real script in a scratch git
 * repository, with stand-ins for git push, gh, az and npm, and with the three
 * scripts it calls replaced by ones that only record how they were called. No
 * network, GitHub or Azure is touched.
 */

function bootstrapWrite(string $path, string $contents, bool $executable = true): void
{
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $contents);

    if ($executable) {
        chmod($path, 0755);
    }
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/bootstrap-'.uniqid();
    $this->work = "{$this->root}/work";
    $this->bin = "{$this->root}/bin";
    $this->state = "{$this->root}/state";
    $this->log = "{$this->root}/log";

    File::ensureDirectoryExists($this->state);
    File::put($this->log, '');

    // The scratch app: a git repository on main whose origin looks like GitHub.
    File::ensureDirectoryExists("{$this->work}/deploy");
    File::ensureDirectoryExists("{$this->work}/node_modules");
    File::copy(base_path('deploy/bootstrap.sh'), "{$this->work}/deploy/bootstrap.sh");
    File::copy(base_path('deploy/lib.sh'), "{$this->work}/deploy/lib.sh");
    chmod("{$this->work}/deploy/bootstrap.sh", 0755);
    File::ensureDirectoryExists("{$this->work}/resources/js/components");
    File::copy(base_path('resources/js/components/AppLogoIcon.vue'), "{$this->work}/resources/js/components/AppLogoIcon.vue");
    File::put("{$this->work}/.env.example", "APP_NAME=\"My App\"\nAPP_TAGLINE=\"A short line about what your app does.\"\nAPP_ENV=local\n");

    // The scripts bootstrap runs are replaced by ones that record how they were run.
    bootstrapWrite("{$this->work}/deploy/azure-provision.sh", <<<'SH'
        #!/bin/bash
        echo "provision SQL=${SQL_ADMIN_PASSWORD:-} USER=${GHCR_USERNAME:-} PAT=${GHCR_PAT:-} TITLE=${APP_TITLE:-} TAGLINE=${APP_TAGLINE:-}" >> "$FAKE_LOG"
        if [ "${FAKE_PROVISION_FAIL:-}" = 1 ]; then echo "provision failed" >&2; exit 1; fi
        SH);
    bootstrapWrite("{$this->work}/deploy/azure-easyauth-setup.sh", <<<'SH'
        #!/bin/bash
        echo "easyauth" >> "$FAKE_LOG"
        SH);
    bootstrapWrite("{$this->work}/deploy/azure-oidc-setup.sh", <<<'SH'
        #!/bin/bash
        echo "oidc ENABLE=${ENABLE_DEPLOY_ON_MERGE:-unset}" >> "$FAKE_LOG"
        SH);

    $git = (new ExecutableFinder)->find('git');
    $run = fn (string $command) => Process::path($this->work)->run($command)->throw();
    $run('git init -q -b main');
    $run('git config user.email test@example.test');
    $run('git config user.name Test');
    $run('git remote add origin git@github.com:acme/acme-tracker.git');
    $run('git add -A');
    $run('git commit -q -m initial');

    // Stand-ins for the programs bootstrap calls. Each records what it was given.
    bootstrapWrite("{$this->bin}/git", <<<SH
        #!/bin/bash
        if [ "\$1" = push ]; then echo "git \$*" >> "\$FAKE_LOG"; exit 0; fi
        exec {$git} "\$@"
        SH);
    bootstrapWrite("{$this->bin}/node", "#!/bin/bash\nexit 0\n");
    bootstrapWrite("{$this->bin}/npm", <<<'SH'
        #!/bin/bash
        echo "npm $*" >> "$FAKE_LOG"
        if [ "$1 $2" = "run brand" ]; then
          shift 3
          while [ $# -gt 0 ]; do
            case "$1" in
              --name) name="$2" ;;
              --tagline) tagline="$2" ;;
            esac
            shift 2
          done
          printf 'APP_NAME="%s"\nAPP_TAGLINE="%s"\nAPP_ENV=local\n' "$name" "$tagline" > .env.example
        fi
        SH);
    bootstrapWrite("{$this->bin}/az", <<<'SH'
        #!/bin/bash
        echo "az $*" >> "$FAKE_LOG"
        if [ "$1 $2" = "account show" ]; then [ "${FAKE_AZ:-ok}" = ok ]; exit $?; fi
        if [ "$1 $2" = "containerapp show" ]; then
          case "$*" in
            *--query*) echo "acme-tracker.example.io" ;;
            *) [ "${FAKE_APP_EXISTS:-no}" = yes ]; exit $? ;;
          esac
        fi
        SH);
    bootstrapWrite("{$this->bin}/gh", <<<'SH'
        #!/bin/bash
        echo "gh $*" >> "$FAKE_LOG"
        state="$FAKE_STATE"
        case "$1 $2" in
          "auth status") [ "${FAKE_GH:-ok}" = ok ]; exit $? ;;
          "workflow run") touch "$state/dispatched"; exit 0 ;;
          "run list") echo 100; [ -f "$state/dispatched" ] && echo 101; exit 0 ;;
          "run view")
            case "$*" in
              *"--json jobs"*)
                n=$(cat "$state/polls" 2>/dev/null || echo 0); n=$((n + 1)); echo "$n" > "$state/polls"
                if [ "$n" -le "${FAKE_BUILD_AFTER:-0}" ]; then exit 0; fi
                case "${FAKE_BUILD:-success}" in success) echo success ;; failure) echo failure ;; esac
                exit 0 ;;
              *"--json status"*) echo "${FAKE_RUN_STATUS:-in_progress}"; exit 0 ;;
            esac ;;
        esac
        if [ "$1" = api ]; then
          case "$*" in
            *"--method PUT"*)
              cat > "$state/put-body"
              if [ "${FAKE_PROTECT_PUT:-ok}" = denied ]; then
                echo "Upgrade to GitHub Pro or make this repository public to enable this feature. (HTTP 403)" >&2
                exit 1
              fi
              exit 0 ;;
            *)
              case "${FAKE_PROTECTION:-none}" in
                none) exit 1 ;;
                ci) echo 0; exit 0 ;;
                other) echo null; exit 0 ;;
              esac ;;
          esac
        fi
        SH);

    $this->path = "{$this->bin}:".dirname($git).':/usr/bin:/bin';
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function bootstrapRun(object $test, array $arguments = [], array $env = [])
{
    return Process::path($test->work)
        ->env([
            // The app's own environment must not leak into the script under test.
            'APP_NAME' => false,
            'APP_TITLE' => false,
            'APP_TAGLINE' => false,
            'SQL_ADMIN_PASSWORD' => false,
            'GHCR_USERNAME' => false,
            'GHCR_PAT' => false,
            'ENABLE_DEPLOY_ON_MERGE' => false,
            'PATH' => $test->path,
            'FAKE_LOG' => $test->log,
            'FAKE_STATE' => $test->state,
            'POLL_SECONDS' => '0',
            'WAIT_MINUTES' => '1',
            ...$env,
        ])
        ->run([(new ExecutableFinder)->find('bash'), 'deploy/bootstrap.sh', ...$arguments]);
}

function bootstrapLog(object $test): array
{
    return array_values(array_filter(explode("\n", File::get($test->log))));
}

function bootstrapCalled(object $test, string $prefix): array
{
    return array_values(array_filter(bootstrapLog($test), fn (string $line) => str_starts_with($line, $prefix)));
}

function bootstrapPosition(object $test, string $prefix): int|false
{
    foreach (bootstrapLog($test) as $index => $line) {
        if (str_starts_with($line, $prefix)) {
            return $index;
        }
    }

    return false;
}

$full = ['--name', 'Acme Tracker', '--tagline', 'Track everything.', '--icon', 'brain'];
$password = ['SQL_ADMIN_PASSWORD' => 'S3cret!pw'];

test('a full run sets the branding, builds the image, sets everything up in order and prints the address', function () use ($full, $password) {
    $result = bootstrapRun($this, $full, $password);

    expect($result->successful())->toBeTrue($result->errorOutput().$result->output());

    $steps = ['npm run brand', 'git push', 'gh workflow run', 'provision', 'easyauth', 'gh api --method PUT', 'oidc'];
    $positions = array_map(fn (string $step) => bootstrapPosition($this, $step), $steps);

    expect($positions)->each->not->toBeFalse()
        ->and($positions)->toBe(collect($positions)->sort()->values()->all())
        ->and($result->output())->toContain('https://acme-tracker.example.io');
});

test('the name, tagline and icon are given to the branding command and committed', function () use ($full, $password) {
    bootstrapRun($this, $full, $password);

    expect(bootstrapCalled($this, 'npm run brand'))
        ->toBe(['npm run brand -- --name Acme Tracker --tagline Track everything. --icon brain']);

    $commit = Process::path($this->work)->run('git log -1 --format=%s')->output();

    expect(trim($commit))->toBe('Set the app name, tagline and icon')
        ->and(File::get("{$this->work}/.env.example"))->toContain('APP_NAME="Acme Tracker"');
    expect(bootstrapCalled($this, 'git push'))->toBe(['git push origin main']);
});

test('the secrets are passed to provisioning and the username defaults to the owner', function () use ($full, $password) {
    bootstrapRun($this, $full, [...$password, 'GHCR_PAT' => 'ghp_token']);

    expect(bootstrapCalled($this, 'provision'))
        ->toBe(['provision SQL=S3cret!pw USER=acme PAT=ghp_token TITLE= TAGLINE=']);
});

test('a public image needs no token', function () use ($full, $password) {
    bootstrapRun($this, $full, $password);

    expect(bootstrapCalled($this, 'provision'))->toBe(['provision SQL=S3cret!pw USER= PAT= TITLE= TAGLINE=']);
});

test('without options it uses the repository name in title case, the current tagline and icon', function () use ($password) {
    $result = bootstrapRun($this, [], $password);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(bootstrapCalled($this, 'npm run brand'))->toBe([
            'npm run brand -- --name Acme Tracker --tagline A short line about what your app does. --icon rocket',
        ]);
});

test('the name in .env.example is the default when it has been set already', function () use ($password) {
    File::put("{$this->work}/.env.example", "APP_NAME=\"Already Named\"\nAPP_TAGLINE=\"Already tagged.\"\n");
    Process::path($this->work)->run('git commit -q -am named')->throw();

    bootstrapRun($this, [], $password);

    expect(bootstrapCalled($this, 'npm run brand'))
        ->toBe(['npm run brand -- --name Already Named --tagline Already tagged. --icon rocket']);
});

test('a stray APP_TITLE or APP_TAGLINE setting cannot override the name that was set', function () use ($full, $password) {
    bootstrapRun($this, $full, [...$password, 'APP_TITLE' => 'Wrong', 'APP_TAGLINE' => 'Wrong']);

    expect(bootstrapCalled($this, 'provision'))->toBe(['provision SQL=S3cret!pw USER= PAT= TITLE= TAGLINE=']);
});

test('dependencies are installed first when there are none', function () use ($full, $password) {
    File::deleteDirectory("{$this->work}/node_modules");

    bootstrapRun($this, $full, $password);

    expect(bootstrapPosition($this, 'npm ci'))->not->toBeFalse()
        ->and(bootstrapPosition($this, 'npm ci'))->toBeLessThan(bootstrapPosition($this, 'npm run brand'));
});

test('nothing is committed when the branding changes nothing', function () use ($password) {
    // The fake brand command writes exactly what is already there.
    File::put("{$this->work}/.env.example", "APP_NAME=\"Acme Tracker\"\nAPP_TAGLINE=\"Track everything.\"\nAPP_ENV=local\n");
    Process::path($this->work)->run('git commit -q -am same')->throw();

    $result = bootstrapRun($this, ['--name', 'Acme Tracker', '--tagline', 'Track everything.', '--icon', 'rocket'], $password);

    expect($result->output())->toContain('Nothing changed')
        ->and(bootstrapCalled($this, 'git push'))->toBe([]);
});

test('the first image is not built again when the app already exists', function () use ($full, $password) {
    $result = bootstrapRun($this, $full, [...$password, 'FAKE_APP_EXISTS' => 'yes']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('already exists')
        ->and(bootstrapCalled($this, 'gh workflow run'))->toBe([])
        ->and(bootstrapCalled($this, 'provision'))->toHaveCount(1)
        ->and(bootstrapCalled($this, 'oidc'))->toHaveCount(1);
});

test('it waits while the image is still building', function () use ($full, $password) {
    $result = bootstrapRun($this, $full, [...$password, 'FAKE_BUILD_AFTER' => '3']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(File::get("{$this->state}/polls"))->toContain('4');
});

test('a failed image build stops everything before Azure is touched', function () use ($full, $password) {
    $result = bootstrapRun($this, $full, [...$password, 'FAKE_BUILD' => 'failure']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('The image build failed')
        ->and($result->errorOutput())->toContain('actions/runs/101')
        ->and($result->errorOutput())->toContain('Stopped during: 2/6 First image')
        ->and(bootstrapCalled($this, 'provision'))->toBe([])
        ->and(bootstrapCalled($this, 'oidc'))->toBe([]);
});

test('it gives up when the image takes too long', function () use ($full, $password) {
    // POLL_SECONDS is 0, so a limit of 0 minutes is reached on the first look.
    $result = bootstrapRun($this, $full, [...$password, 'FAKE_BUILD_AFTER' => '99', 'WAIT_MINUTES' => '0']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('Gave up waiting')
        ->and(bootstrapCalled($this, 'provision'))->toBe([]);
});

test('a step that fails says where it stopped and the later steps do not run', function () use ($full, $password) {
    $result = bootstrapRun($this, $full, [...$password, 'FAKE_PROVISION_FAIL' => '1']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('Stopped during: 3/6 Provisioning Azure')
        ->and($result->errorOutput())->toContain('run this again')
        ->and(bootstrapCalled($this, 'easyauth'))->toBe([])
        ->and(bootstrapCalled($this, 'oidc'))->toBe([]);
});

test('main is protected with the ci check and deploying on merge is left on', function () use ($full, $password) {
    $result = bootstrapRun($this, $full, $password);

    $body = json_decode(File::get("{$this->state}/put-body"), true);

    expect($result->output())->toContain('now requires the ci check')
        ->and($body['required_status_checks']['contexts'])->toBe(['ci'])
        ->and($body['required_pull_request_reviews'])->toBeNull()
        ->and(bootstrapCalled($this, 'oidc'))->toBe(['oidc ENABLE=true']);
});

test('when main cannot be protected, deploying on merge is left off and the reason is shown', function () use ($full, $password) {
    $result = bootstrapRun($this, $full, [...$password, 'FAKE_PROTECT_PUT' => 'denied']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('could not be protected: Upgrade to GitHub Pro')
        ->and($result->output())->toContain('left off')
        ->and($result->output())->toContain('Merging to main does not deploy')
        ->and(bootstrapCalled($this, 'oidc'))->toBe(['oidc ENABLE=false']);
});

test('protection that already requires ci is left alone and deploying stays on', function () use ($full, $password) {
    $result = bootstrapRun($this, $full, [...$password, 'FAKE_PROTECTION' => 'ci']);

    expect($result->output())->toContain('already requires the ci check')
        ->and(File::exists("{$this->state}/put-body"))->toBeFalse()
        ->and(bootstrapCalled($this, 'oidc'))->toBe(['oidc ENABLE=true']);
});

test('protection that does not require ci is not overwritten and deploying is left off', function () use ($full, $password) {
    $result = bootstrapRun($this, $full, [...$password, 'FAKE_PROTECTION' => 'other']);

    expect($result->output())->toContain('left as it is')
        ->and(File::exists("{$this->state}/put-body"))->toBeFalse()
        ->and(bootstrapCalled($this, 'oidc'))->toBe(['oidc ENABLE=false']);
});

test('an explicit ENABLE_DEPLOY_ON_MERGE setting is respected', function () use ($full, $password) {
    bootstrapRun($this, $full, [...$password, 'ENABLE_DEPLOY_ON_MERGE' => 'false']);

    expect(bootstrapCalled($this, 'oidc'))->toBe(['oidc ENABLE=false']);
});

test('without a SQL admin password it stops before changing anything', function () use ($full) {
    $result = bootstrapRun($this, $full);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('Set SQL_ADMIN_PASSWORD')
        ->and(bootstrapCalled($this, 'npm run brand'))->toBe([])
        ->and(bootstrapCalled($this, 'git push'))->toBe([])
        ->and(bootstrapCalled($this, 'gh workflow'))->toBe([]);
});

test('it stops before changing anything when a check fails', function (array $env, string $expected, ?callable $arrange) use ($full, $password) {
    if ($arrange) {
        $arrange($this);
    }

    $result = bootstrapRun($this, $full, [...$password, ...$env]);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain($expected)
        ->and(bootstrapCalled($this, 'npm run brand'))->toBe([])
        ->and(bootstrapCalled($this, 'git push'))->toBe([])
        ->and(bootstrapCalled($this, 'gh workflow'))->toBe([])
        ->and(bootstrapCalled($this, 'provision'))->toBe([]);
})->with([
    'not logged in to GitHub' => [['FAKE_GH' => 'no'], 'gh auth login', null],
    'not logged in to Azure' => [['FAKE_AZ' => 'no'], 'az login', null],
    'not on main' => [[], 'Run this from the main branch', fn ($test) => Process::path($test->work)->run('git checkout -q -b feature')->throw()],
    'uncommitted changes' => [[], 'uncommitted changes', fn ($test) => File::put("{$test->work}/stray.txt", 'x')],
    'an origin that is not GitHub' => [[], "'origin' is a GitHub repository", fn ($test) => Process::path($test->work)->run('git remote set-url origin https://example.com/acme/acme-tracker.git')->throw()],
    'a repository name Azure cannot use' => [[], 'is not valid', fn ($test) => Process::path($test->work)->run('git remote set-url origin git@github.com:acme/Acme_Tracker.git')->throw()],
]);

test('a missing tool is reported by name', function () use ($full, $password) {
    // A PATH with git and bash but without the stand-in programs and without node.
    $isolated = "{$this->root}/isolated";
    File::ensureDirectoryExists($isolated);
    foreach (['git', 'bash', 'sed', 'tr', 'grep', 'head', 'dirname', 'cat'] as $program) {
        $found = (new ExecutableFinder)->find($program);
        if ($found) {
            symlink($found, "{$isolated}/{$program}");
        }
    }
    $this->path = $isolated;

    $result = bootstrapRun($this, $full, $password);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain("'node' is not installed");
});

test('an option that does not exist, or has no value, is refused', function (array $arguments, string $expected) {
    $result = bootstrapRun($this, $arguments, ['SQL_ADMIN_PASSWORD' => 'x']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain($expected)
        ->and(bootstrapCalled($this, 'npm run brand'))->toBe([]);
})->with([
    'unknown' => [['--nope'], "Unknown option '--nope'"],
    'no value' => [['--name'], '--name needs a value'],
]);

test('help lists the steps and changes nothing', function () {
    $result = bootstrapRun($this, ['--help']);

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toContain('Branding')
        ->and($result->output())->toContain('--yes')
        ->and(bootstrapLog($this))->toBe([]);
});
