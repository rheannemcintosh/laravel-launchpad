<?php

use Illuminate\Support\Facades\Process;
use Tests\Support\AzureHarness;

/*
 * deploy/azure-provision.sh creates everything an app needs in Azure. These tests run
 * the real script with stand-ins for az (see tests/Support), so nothing reaches Azure.
 */

beforeEach(function () {
    $this->azure = new AzureHarness;
});

afterEach(function () {
    $this->azure->cleanup();
});

function provision(object $test, array $env = [])
{
    return $test->azure->run('azure-provision.sh', ['SQL_ADMIN_PASSWORD' => 'S3cret!pw', ...$env]);
}

function provisionServer(object $test): string
{
    return array_key_first($test->azure->state()['sql_servers']);
}

test('a first provision creates everything, named from the repository', function () {
    $result = provision($this);
    $state = $this->azure->state();
    $server = provisionServer($this);

    expect($result->successful())->toBeTrue($result->errorOutput().$result->output())
        ->and($state['groups'])->toHaveKey('rg-acme-tracker')
        ->and($state['groups']['rg-acme-tracker']['location'])->toBe('uksouth')
        ->and($server)->toMatch('/^acme-tracker-sql-\d+$/')
        ->and($state['sql_servers'][$server]['admin_user'])->toBe('acme_tracker_admin')
        ->and($state['sql_servers'][$server]['admin_password'])->toBe('S3cret!pw')
        ->and($state['sql_servers'][$server]['firewall'])->toBe(['AllowAzureServices'])
        ->and($state['sql_dbs'])->toHaveKey("{$server}/acme_tracker")
        ->and($state['envs'])->toHaveKey('acme-tracker-env')
        ->and($state['apps'])->toHaveKey('acme-tracker')
        ->and($state['extensions'])->toBe(['containerapp']);
});

test('the database is the free serverless offer, and the environment has no log analytics', function () {
    provision($this);
    $server = provisionServer($this);
    $database = implode(' ', $this->azure->state()['sql_dbs']["{$server}/acme_tracker"]['args']);
    $environment = implode(' ', $this->azure->state()['envs']['acme-tracker-env']['args']);

    expect($database)->toContain('--compute-model Serverless')
        ->toContain('--use-free-limit')
        ->toContain('--free-limit-exhaustion-behavior AutoPause')
        ->toContain('--auto-pause-delay 60')
        ->and($environment)->toContain('--logs-destination none');
});

test('the container app scales to zero, uses the image from the repository owner and is created behind deny-all sign-in', function () {
    provision($this);
    $app = $this->azure->state()['apps']['acme-tracker'];
    $arguments = implode(' ', $app['args']);

    expect($app['image'])->toBe('ghcr.io/acme/acme-tracker:latest')
        ->and($app['group'])->toBe('rg-acme-tracker')
        ->and($app['environment'])->toBe('acme-tracker-env')
        ->and($arguments)->toContain('--min-replicas 0 --max-replicas 1')
        ->and($arguments)->toContain('--target-port 8080')
        ->and($app['registry'])->toBeNull()
        ->and($app['auth']['enabled'])->toBeTrue()
        ->and($app['auth']['action'])->toBe('Return403');
});

test('the app gets production settings, the database address and the real address', function () {
    provision($this);
    $server = provisionServer($this);
    $app = $this->azure->state()['apps']['acme-tracker'];

    expect($app['env_vars'])->toMatchArray([
        'APP_NAME' => 'Acme Tracker',
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_KEY' => 'secretref:app-key',
        'DB_CONNECTION' => 'sqlsrv',
        'DB_HOST' => "{$server}.database.windows.net",
        'DB_DATABASE' => 'acme_tracker',
        'DB_USERNAME' => 'acme_tracker_admin',
        'DB_PASSWORD' => 'secretref:db-password',
        'DB_ENCRYPT' => 'yes',
        'SESSION_DRIVER' => 'cookie',
        'SESSION_SECURE_COOKIE' => 'true',
        'QUEUE_CONNECTION' => 'sync',
        'APP_URL' => 'https://acme-tracker.fake.uksouth.azurecontainerapps.io',
    ])->and($app['env_vars'])->not->toHaveKey('APP_TAGLINE');
});

test('a key is generated once and the passwords are stored as secrets, never printed', function () {
    $result = provision($this, ['GHCR_USERNAME' => 'acme', 'GHCR_PAT' => 'ghp_secret_token']);
    $secrets = $this->azure->state()['apps']['acme-tracker']['secrets'];

    expect($secrets['app-key'])->toStartWith('base64:')
        ->and($secrets['db-password'])->toBe('S3cret!pw')
        ->and($result->output().$result->errorOutput())
        ->not->toContain('S3cret!pw')
        ->not->toContain('ghp_secret_token')
        ->not->toContain($secrets['app-key']);
});

test('a budget alert goes to the signed-in user at 80 and 100 percent', function () {
    provision($this);
    $budget = $this->azure->state()['budgets']['rg-acme-tracker/acme-tracker-monthly']['properties'];
    $notifications = collect($budget['notifications']);

    expect($budget['amount'])->toBe(1)
        ->and($budget['category'])->toBe('Cost')
        ->and($budget['timeGrain'])->toBe('Monthly')
        ->and($budget['timePeriod']['startDate'])->toEndWith('-01T00:00:00Z')
        ->and($notifications->pluck('threshold')->sort()->values()->all())->toBe([80, 100])
        ->and($notifications->every(fn (array $n) => $n['contactEmails'] === ['me@example.com'] && $n['enabled']))->toBeTrue();
});

test('a first provision prints the address and the next steps', function () {
    $result = provision($this);

    expect($result->output())
        ->toContain('https://acme-tracker.fake.uksouth.azurecontainerapps.io')
        ->toContain('NEXT STEPS')
        ->toContain('HTTP 403');
});

test('provider registration happens only for providers that are not registered', function () {
    $this->azure->seed(['providers' => ['Microsoft.Sql' => 'Registered', 'Microsoft.App' => 'Registered']]);

    provision($this);

    expect($this->azure->calls('az provider register'))->toBe(['az provider register --namespace Microsoft.OperationalInsights --wait --output none']);
});

test('nothing is registered when every provider already is', function () {
    $this->azure->provisioned();

    provision($this);

    expect($this->azure->calls('az provider register'))->toBe([]);
});

test('running it again reuses everything and keeps the key, the data and the sign-in settings', function () {
    provision($this);
    $before = $this->azure->state();
    $key = $before['apps']['acme-tracker']['secrets']['app-key'];

    $second = provision($this);
    $after = $this->azure->state();

    expect($second->successful())->toBeTrue($second->errorOutput())
        ->and(array_keys($after['sql_servers']))->toBe(array_keys($before['sql_servers']))
        ->and($after['apps']['acme-tracker']['secrets']['app-key'])->toBe($key)
        ->and($after['apps']['acme-tracker']['auth'])->toBe($before['apps']['acme-tracker']['auth'])
        ->and($second->output())->toContain('reusing')
        ->and($second->output())->not->toContain('NEXT STEPS');

    $creates = collect($this->azure->log())->filter(fn (string $line) => preg_match('/^az (sql server create|sql db create|containerapp env create|containerapp create|containerapp auth update|group delete)/', $line));

    // Each create-type call happened once, in the first run only.
    expect($creates->countBy(fn (string $line) => explode(' --', $line)[0])->all())
        ->toBe(['az sql server create' => 1, 'az sql db create' => 1, 'az containerapp env create' => 1, 'az containerapp create' => 1, 'az containerapp auth update' => 1]);
});

test('running it again refreshes the image, the settings, the password and the budget', function () {
    provision($this);
    $this->azure->seed(['apps' => ['acme-tracker' => ['image' => 'ghcr.io/acme/acme-tracker:old', 'env_vars' => ['APP_DEBUG' => 'true']]]]);

    provision($this, ['SQL_ADMIN_PASSWORD' => 'N3w-passw0rd!']);
    $app = $this->azure->state()['apps']['acme-tracker'];

    expect($app['image'])->toBe('ghcr.io/acme/acme-tracker:latest')
        ->and($app['env_vars']['APP_DEBUG'])->toBe('false')
        ->and($app['secrets']['db-password'])->toBe('N3w-passw0rd!')
        ->and($this->azure->calls('az rest --method put'))->toHaveCount(2);
});

test('a private image gets pull credentials, and a re-run refreshes them', function () {
    provision($this, ['GHCR_USERNAME' => 'acme', 'GHCR_PAT' => 'ghp_first']);

    expect($this->azure->state()['apps']['acme-tracker']['registry'])
        ->toBe(['server' => 'ghcr.io', 'username' => 'acme', 'password' => 'ghp_first']);

    provision($this, ['GHCR_USERNAME' => 'acme', 'GHCR_PAT' => 'ghp_second']);

    expect($this->azure->state()['apps']['acme-tracker']['registry']['password'])->toBe('ghp_second')
        ->and($this->azure->calls('az containerapp registry set'))->toHaveCount(1);
});

test('a re-run without a token leaves the pull credentials alone', function () {
    provision($this, ['GHCR_USERNAME' => 'acme', 'GHCR_PAT' => 'ghp_first']);
    provision($this);

    expect($this->azure->calls('az containerapp registry set'))->toBe([])
        ->and($this->azure->state()['apps']['acme-tracker']['registry']['password'])->toBe('ghp_first');
});

test('the settings override the defaults', function () {
    $result = provision($this, [
        'LOCATION' => 'westeurope',
        'BUDGET_AMOUNT' => '7.5',
        'BUDGET_EMAIL' => 'alerts@example.org',
        'IMAGE' => 'ghcr.io/elsewhere/custom:1.2',
        'APP_KEY' => 'base64:chosen',
        'SQL_SERVER' => 'pinned-server',
    ]);
    $state = $this->azure->state();

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($state['groups']['rg-acme-tracker']['location'])->toBe('westeurope')
        ->and($state['apps']['acme-tracker']['image'])->toBe('ghcr.io/elsewhere/custom:1.2')
        ->and($state['apps']['acme-tracker']['secrets']['app-key'])->toBe('base64:chosen')
        ->and($state['sql_servers'])->toHaveKey('pinned-server')
        ->and($state['budgets']['rg-acme-tracker/acme-tracker-monthly']['properties']['amount'])->toBe(7.5)
        ->and(collect($state['budgets']['rg-acme-tracker/acme-tracker-monthly']['properties']['notifications'])->every(fn (array $n) => $n['contactEmails'] === ['alerts@example.org']))->toBeTrue();
});

test('the app name and owner can be given instead of read from the repository', function () {
    provision($this, ['APP_NAME' => 'my-cool-app', 'GITHUB_OWNER' => 'Other']);
    $state = $this->azure->state();

    expect($state['groups'])->toHaveKey('rg-my-cool-app')
        ->and($state['apps'])->toHaveKey('my-cool-app')
        ->and($state['apps']['my-cool-app']['image'])->toBe('ghcr.io/other/my-cool-app:latest')
        ->and($state['envs'])->toHaveKey('my-cool-app-env');
});

test('a name with 32 characters is accepted', function () {
    $name = 'a'.str_repeat('b', 31);

    $result = provision($this, ['APP_NAME' => $name]);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($this->azure->state()['apps'])->toHaveKey($name);
});

test('the name and tagline shown by the app come from .env.example', function () {
    $this->azure->setEnvExample("APP_NAME=\"Acme Tracker Pro\"\nAPP_TAGLINE=\"Track everything, quietly.\"\n");

    provision($this);
    $env = $this->azure->state()['apps']['acme-tracker']['env_vars'];

    expect($env['APP_NAME'])->toBe('Acme Tracker Pro')
        ->and($env['APP_TAGLINE'])->toBe('Track everything, quietly.');
});

test('the template placeholder name falls back to the repository name in title case', function () {
    $this->azure->setEnvExample("APP_NAME=\"My App\"\nAPP_TAGLINE=\"A tagline.\"\n");

    provision($this);

    expect($this->azure->state()['apps']['acme-tracker']['env_vars']['APP_NAME'])->toBe('Acme Tracker');
});

test('APP_TITLE and APP_TAGLINE settings win over .env.example', function () {
    $this->azure->setEnvExample("APP_NAME=\"From The File\"\nAPP_TAGLINE=\"From the file.\"\n");

    provision($this, ['APP_TITLE' => 'From The Setting', 'APP_TAGLINE' => 'From the setting.']);
    $env = $this->azure->state()['apps']['acme-tracker']['env_vars'];

    expect($env['APP_NAME'])->toBe('From The Setting')
        ->and($env['APP_TAGLINE'])->toBe('From the setting.');
});

test('with no email address to notify, the budget is skipped and the rest still works', function () {
    $this->azure->seed(['account' => ['user' => 'someone_outlook.com#EXT#@tenant.onmicrosoft.com']]);

    $result = provision($this);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('Set BUDGET_EMAIL')
        ->and($this->azure->state()['budgets'])->toBe([])
        ->and($this->azure->calls('az rest'))->toBe([]);
});

test('a budget that cannot be created is reported, and the rest still works', function () {
    $result = provision($this, ['FAKE_FAIL' => 'az rest put']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('budget create failed')
        ->and($this->azure->state()['apps'])->toHaveKey('acme-tracker');
});

test('without a SQL admin password it stops before calling Azure', function () {
    $result = $this->azure->run('azure-provision.sh');

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('SQL_ADMIN_PASSWORD')
        ->and($this->azure->log())->toBe([]);
});

test('a name Azure cannot use is refused before Azure is called', function (string $name) {
    $result = provision($this, ['APP_NAME' => $name]);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('is not valid')
        ->and($this->azure->log())->toBe([]);
})->with(['Bad_Name', 'a--b', 'x', '-lead', 'trail-', str_repeat('a', 33), '1abc', 'UPPER_case']);

test('a budget that is not a number is refused before Azure is called', function () {
    $result = provision($this, ['BUDGET_AMOUNT' => 'a lot']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('BUDGET_AMOUNT')
        ->and($this->azure->log())->toBe([]);
});

test('without a git remote or an APP_NAME it asks for one', function () {
    Process::path($this->azure->work)->run('git remote remove origin')->throw();

    $result = provision($this);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('Set APP_NAME')
        ->and($this->azure->log())->toBe([]);
});

test('without an owner for the image it asks for one', function () {
    Process::path($this->azure->work)->run('git remote remove origin')->throw();

    $result = provision($this, ['APP_NAME' => 'acme-tracker']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('GITHUB_OWNER');
});

test('not being signed in to Azure stops it with the way to fix it', function () {
    $this->azure->seed(['account' => ['logged_in' => false]]);

    $result = provision($this);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('az login')
        ->and($this->azure->calls('az group create'))->toBe([])
        ->and($this->azure->calls('az sql'))->toBe([]);
});

test('several SQL servers in the group ask for the right one to be named', function () {
    $this->azure->seed([
        'groups' => ['rg-acme-tracker' => ['location' => 'uksouth']],
        'sql_servers' => [
            'acme-tracker-sql-1' => ['group' => 'rg-acme-tracker', 'admin_user' => 'x', 'admin_password' => 'x'],
            'acme-tracker-sql-2' => ['group' => 'rg-acme-tracker', 'admin_user' => 'x', 'admin_password' => 'x'],
        ],
    ]);

    $result = provision($this);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('SQL_SERVER')
        ->and($this->azure->calls('az sql server create'))->toBe([])
        ->and($this->azure->calls('az containerapp create'))->toBe([]);
});

test('naming one of several SQL servers uses it', function () {
    $this->azure->seed([
        'groups' => ['rg-acme-tracker' => ['location' => 'uksouth']],
        'sql_servers' => [
            'acme-tracker-sql-1' => ['group' => 'rg-acme-tracker', 'admin_user' => 'x', 'admin_password' => 'x'],
            'acme-tracker-sql-2' => ['group' => 'rg-acme-tracker', 'admin_user' => 'x', 'admin_password' => 'x'],
        ],
    ]);

    $result = provision($this, ['SQL_SERVER' => 'acme-tracker-sql-2']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($this->azure->state()['apps']['acme-tracker']['env_vars']['DB_HOST'])->toBe('acme-tracker-sql-2.database.windows.net')
        ->and($this->azure->calls('az sql server create'))->toBe([]);
});

test('an existing single SQL server is found and reused', function () {
    $this->azure->provisioned();

    $result = provision($this);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('reusing acme-tracker-sql-1234')
        ->and($this->azure->calls('az sql server create'))->toBe([])
        ->and($this->azure->calls('az sql db create'))->toBe([]);
});
