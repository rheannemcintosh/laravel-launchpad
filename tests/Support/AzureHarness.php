<?php

namespace Tests\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Runs the real scripts in deploy/ in a scratch git repository, with stand-ins for
 * az, gh and curl on the PATH (see tests/Support/fakes). Nothing reaches Azure or
 * GitHub. What the stand-ins create is kept in a JSON file the test can read and
 * seed, and every call they receive is logged.
 */
final class AzureHarness
{
    /** Settings the scripts read, cleared so the test machine's own environment cannot leak in. */
    private const CLEARED = [
        'APP_NAME', 'APP_TITLE', 'APP_TAGLINE', 'APP_KEY', 'SQL_ADMIN_PASSWORD', 'GHCR_USERNAME', 'GHCR_PAT',
        'LOCATION', 'IMAGE', 'SQL_SERVER', 'BUDGET_AMOUNT', 'BUDGET_EMAIL', 'GITHUB_OWNER', 'GITHUB_REPO',
        'TRUST_BRANCH', 'ENABLE_DEPLOY_ON_MERGE', 'OIDC_SUBJECT', 'ALLOWED_USER_ID', 'ROTATE_SECRET', 'VERIFY',
        'DRY_RUN', 'FORCE', 'NO_WAIT', 'REMOVE_SECRETS', 'FAKE_FAIL', 'FAKE_GROUP_STAYS', 'FAKE_CURL_NEVER',
    ];

    public string $root;

    public string $work;

    public string $bin;

    public string $state;

    public function __construct(public string $owner = 'acme', public string $repo = 'acme-tracker')
    {
        $this->root = sys_get_temp_dir().'/azure-'.uniqid();
        $this->work = "{$this->root}/work";
        $this->bin = "{$this->root}/bin";
        $this->state = "{$this->root}/state";

        File::ensureDirectoryExists($this->state);
        File::ensureDirectoryExists($this->bin);
        File::ensureDirectoryExists("{$this->work}/deploy");

        foreach (['lib.php', 'az', 'gh', 'curl'] as $file) {
            File::copy(base_path("tests/Support/fakes/{$file}"), "{$this->bin}/{$file}");
            chmod("{$this->bin}/{$file}", 0755);
        }

        foreach (File::glob(base_path('deploy/*.sh')) as $script) {
            File::copy($script, "{$this->work}/deploy/".basename($script));
            chmod("{$this->work}/deploy/".basename($script), 0755);
        }

        Process::path($this->work)->run('git init -q -b main')->throw();
        Process::path($this->work)->run("git remote add origin git@github.com:{$owner}/{$repo}.git")->throw();
    }

    public function cleanup(): void
    {
        File::deleteDirectory($this->root);
    }

    /** @param array<string, string|false> $env */
    public function run(string $script, array $env = [], string $input = ''): ProcessResult
    {
        $finder = new ExecutableFinder;
        $path = implode(':', array_unique([
            $this->bin,
            dirname($finder->find('php')),
            dirname($finder->find('git')),
            '/usr/bin',
            '/bin',
        ]));

        return Process::path($this->work)
            ->input($input)
            ->env([
                ...array_fill_keys(self::CLEARED, false),
                'PATH' => $path,
                'FAKE_STATE' => $this->state,
                'RETRY_DELAY' => '0',
                'VERIFY_DELAY' => '0',
                ...$env,
            ])
            ->run([$finder->find('bash'), "deploy/{$script}"]);
    }

    /** The current state of the stand-ins. */
    public function state(): array
    {
        $path = "{$this->state}/state.json";
        $saved = File::exists($path) ? json_decode(File::get($path), true) : [];

        // With the same defaults the stand-ins fill in, so a state read before a run
        // can be compared with one read after it.
        require_once base_path('tests/Support/fakes/lib.php');

        return array_replace_recursive(fake_defaults(), $saved);
    }

    /** Changes the state before a run. Lists are replaced by position, so give the whole list. */
    public function seed(array $changes): self
    {
        File::put("{$this->state}/state.json", json_encode(array_replace_recursive($this->state(), $changes), JSON_PRETTY_PRINT));

        return $this;
    }

    /** Changes the state before a run with a function that receives it and returns the new state, so things can be removed. */
    public function modify(callable $change): self
    {
        File::put("{$this->state}/state.json", json_encode($change($this->state()), JSON_PRETTY_PRINT));

        return $this;
    }

    /** Every call the stand-ins received, in order. */
    public function log(): array
    {
        $path = "{$this->state}/calls.log";

        return File::exists($path) ? array_values(array_filter(explode("\n", File::get($path)))) : [];
    }

    /** The calls that start with $prefix, such as "az sql server create". */
    public function calls(string $prefix): array
    {
        return array_values(array_filter($this->log(), fn (string $line) => str_starts_with($line, $prefix)));
    }

    /** Where in the log the first call starting with $prefix is, or false. */
    public function position(string $prefix): int|false
    {
        foreach ($this->log() as $index => $line) {
            if (str_starts_with($line, $prefix)) {
                return $index;
            }
        }

        return false;
    }

    public function setEnvExample(string $contents): self
    {
        File::put("{$this->work}/.env.example", $contents);

        return $this;
    }

    /** What provisioning leaves behind for the app, ready for the scripts that come after it. */
    public function provisioned(array $overrides = []): self
    {
        $app = $this->repo;
        $group = "rg-{$app}";

        return $this->seed(array_replace_recursive([
            'providers' => ['Microsoft.Sql' => 'Registered', 'Microsoft.App' => 'Registered', 'Microsoft.OperationalInsights' => 'Registered'],
            'groups' => [$group => ['location' => 'uksouth']],
            'sql_servers' => ["{$app}-sql-1234" => ['group' => $group, 'admin_user' => str_replace('-', '_', $app).'_admin', 'admin_password' => 'S3cret!pw']],
            'sql_dbs' => ["{$app}-sql-1234/".str_replace('-', '_', $app) => ['args' => []]],
            'envs' => ["{$app}-env" => ['group' => $group, 'args' => []]],
            'apps' => [$app => [
                'group' => $group,
                'environment' => "{$app}-env",
                'image' => "ghcr.io/{$this->owner}/{$app}:latest",
                'env_vars' => ['APP_NAME' => 'Acme Tracker'],
                'secrets' => ['app-key' => 'base64:original', 'db-password' => 'S3cret!pw'],
                'registry' => null,
                'auth' => ['enabled' => true, 'action' => 'Return403', 'provider' => null],
                'fqdn' => "{$app}.fake.uksouth.azurecontainerapps.io",
                'revision' => "{$app}--0000001",
                'args' => [],
            ]],
        ], $overrides));
    }
}
