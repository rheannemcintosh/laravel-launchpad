<?php

use Illuminate\Support\Facades\Process;
use Tests\Support\AzureHarness;

/*
 * deploy/azure-oidc-setup.sh sets up the sign-in the deploy workflow uses, and turns on
 * deploying after a merge. These tests run the real script with stand-ins for az and
 * gh (see tests/Support), so nothing reaches Azure or GitHub.
 */

beforeEach(function () {
    $this->azure = new AzureHarness;
    $this->azure->provisioned();
});

afterEach(function () {
    $this->azure->cleanup();
});

function oidc(object $test, array $env = [])
{
    return $test->azure->run('azure-oidc-setup.sh', $env);
}

function oidcApp(object $test): array
{
    return collect($test->azure->state()['ad_apps'])->firstWhere('displayName', 'acme-tracker-deploy');
}

test('a first run creates the deploy identity, limits it to the resource group and trusts the main branch', function () {
    $result = oidc($this);
    $state = $this->azure->state();
    $app = oidcApp($this);

    expect($result->successful())->toBeTrue($result->errorOutput().$result->output())
        ->and($app)->not->toBeNull()
        ->and($state['sps'])->toHaveKey($app['appId'])
        ->and($state['roles'])->toHaveCount(1)
        ->and($state['roles'][0]['role'])->toBe('Contributor')
        ->and($state['roles'][0]['scope'])->toBe('/subscriptions/00000000-0000-4000-8000-000000000001/resourceGroups/rg-acme-tracker')
        ->and($state['roles'][0]['principal_type'])->toBe('ServicePrincipal')
        ->and($state['roles'][0]['assignee'])->toBe($state['sps'][$app['appId']]['id'])
        ->and($app['federated'])->toHaveKey('github-main')
        ->and($app['federated']['github-main']['subject'])->toBe('repo:acme/acme-tracker:ref:refs/heads/main')
        ->and($app['federated']['github-main']['issuer'])->toBe('https://token.actions.githubusercontent.com')
        ->and($app['federated']['github-main']['audiences'])->toBe(['api://AzureADTokenExchange']);
});

test('the role is never wider than the resource group', function () {
    oidc($this);

    $scope = $this->azure->state()['roles'][0]['scope'];

    expect($scope)->toEndWith('/resourceGroups/rg-acme-tracker')
        ->and($this->azure->calls('az role assignment create'))->toHaveCount(1)
        ->and($this->azure->calls('az role assignment create')[0])->toContain('--role Contributor');
});

test('no client secret is created, so there is nothing to store or rotate', function () {
    oidc($this);

    expect($this->azure->calls('az ad app credential'))->toBe([]);
});

test('the three repository secrets and the deploy on merge variable are set', function () {
    $result = oidc($this);
    $github = $this->azure->state()['github'];
    $app = oidcApp($this);

    expect($github['secrets'])->toBe([
        'AZURE_CLIENT_ID' => $app['appId'],
        'AZURE_TENANT_ID' => '00000000-0000-4000-8000-000000000002',
        'AZURE_SUBSCRIPTION_ID' => '00000000-0000-4000-8000-000000000001',
    ])->and($github['variables'])->toBe(['DEPLOY_ON_MERGE' => 'true'])
        ->and($result->output())->toContain('Deploy on merge : on')
        ->and($result->output())->toContain('gh variable delete DEPLOY_ON_MERGE');
});

test('running it again reuses everything and creates nothing twice', function () {
    oidc($this);
    $before = $this->azure->state();

    $second = oidc($this);
    $after = $this->azure->state();

    expect($second->successful())->toBeTrue($second->errorOutput())
        ->and($after['ad_apps'])->toHaveCount(1)
        ->and($after['sps'])->toHaveCount(1)
        ->and($after['roles'])->toHaveCount(1)
        ->and(oidcApp($this)['federated'])->toHaveCount(1)
        ->and($after['github'])->toBe($before['github'])
        ->and($second->output())->toContain('reusing');

    expect($this->azure->calls('az ad app create'))->toHaveCount(1)
        ->and($this->azure->calls('az ad sp create'))->toHaveCount(1)
        ->and($this->azure->calls('az role assignment create'))->toHaveCount(1)
        ->and($this->azure->calls('az ad app federated-credential create'))->toHaveCount(1);
});

test('the subject GitHub reports for the repository is used, including immutable ones', function () {
    $this->azure->seed(['github' => ['oidc_prefix' => 'repo:acme/acme-tracker:repository_id:42:repository_owner_id:7']]);

    oidc($this);

    expect(oidcApp($this)['federated']['github-main']['subject'])
        ->toBe('repo:acme/acme-tracker:repository_id:42:repository_owner_id:7:ref:refs/heads/main');
});

test('a different branch gets its own trust rule name, and deploying on merge stays off', function () {
    $result = oidc($this, ['TRUST_BRANCH' => 'release/1.0']);
    $app = oidcApp($this);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($app['federated'])->toHaveKey('github-release-1-0')
        ->and($app['federated']['github-release-1-0']['subject'])->toBe('repo:acme/acme-tracker:ref:refs/heads/release/1.0')
        ->and($this->azure->state()['github']['variables'])->toBe([])
        ->and($result->output())->toContain('left off: only release/1.0 is trusted, and merges land on main');
});

test('a branch name with unexpected characters is refused before Azure is called', function () {
    $result = oidc($this, ['TRUST_BRANCH' => 'main; rm -rf /']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('not a plain branch name')
        ->and($this->azure->log())->toBe([]);
});

test('deploying on merge can be left off', function () {
    $result = oidc($this, ['ENABLE_DEPLOY_ON_MERGE' => 'false']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($this->azure->state()['github']['variables'])->toBe([])
        ->and($this->azure->calls('gh variable set'))->toBe([])
        ->and($result->output())->toContain('left off (ENABLE_DEPLOY_ON_MERGE is off)')
        ->and($result->output())->toContain('Deploy on merge : off');
});

test('a trust rule for a different subject is updated', function () {
    oidc($this);
    $this->azure->seed(['ad_apps' => [0 => ['federated' => ['github-main' => ['name' => 'github-main', 'subject' => 'repo:someone/else:ref:refs/heads/main']]]]]);

    $result = oidc($this);

    expect($result->output())->toContain('trusted a different subject')
        ->and(oidcApp($this)['federated']['github-main']['subject'])->toBe('repo:acme/acme-tracker:ref:refs/heads/main')
        ->and($this->azure->calls('az ad app federated-credential update'))->toHaveCount(1);
});

test('the subject can be given, and then GitHub is not asked', function () {
    $result = oidc($this, ['OIDC_SUBJECT' => 'repo:acme/acme-tracker:environment:production']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(oidcApp($this)['federated']['github-main']['subject'])->toBe('repo:acme/acme-tracker:environment:production')
        ->and($this->azure->calls('gh api'))->toBe([]);
});

test('a subject with unexpected characters is refused', function () {
    $result = oidc($this, ['OIDC_SUBJECT' => 'repo:acme/acme-tracker; echo bad']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('unexpected characters')
        ->and($this->azure->calls('az ad app create'))->toBe([]);
});

test('if GitHub cannot be asked for the subject it stops before creating anything', function () {
    $result = oidc($this, ['FAKE_FAIL' => 'gh api']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('Could not read the OIDC subject')
        ->and($result->errorOutput())->toContain('OIDC_SUBJECT')
        ->and($this->azure->calls('az ad app create'))->toBe([])
        ->and($this->azure->calls('az role assignment create'))->toBe([]);
});

test('secrets that cannot be set are printed so they can be added by hand', function () {
    $result = oidc($this, ['FAKE_FAIL' => 'gh secret set']);
    $app = oidcApp($this);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('could not set AZURE_CLIENT_ID')
        ->and($result->output())->toContain("AZURE_CLIENT_ID       = {$app['appId']}")
        ->and($result->output())->toContain('AZURE_TENANT_ID       = 00000000-0000-4000-8000-000000000002')
        ->and($result->output())->toContain('AZURE_SUBSCRIPTION_ID = 00000000-0000-4000-8000-000000000001');
});

test('a variable that cannot be set is reported and the rest still works', function () {
    $result = oidc($this, ['FAKE_FAIL' => 'gh variable set']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('could not be set')
        ->and($result->output())->toContain('Deploy on merge : off')
        ->and($this->azure->state()['github']['secrets'])->toHaveCount(3);
});

test('a new identity that Azure has not seen yet is retried', function () {
    $this->azure->seed(['role_create_failures' => 2]);

    $result = oidc($this);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($this->azure->calls('az role assignment create'))->toHaveCount(3)
        ->and($result->output())->toContain('not ready yet, retrying')
        ->and($this->azure->state()['roles'])->toHaveCount(1);
});

test('it gives up after six tries and creates no trust rule', function () {
    $this->azure->seed(['role_create_failures' => 50]);

    $result = oidc($this);

    expect($result->failed())->toBeTrue()
        ->and($this->azure->calls('az role assignment create'))->toHaveCount(6)
        ->and($this->azure->calls('az ad app federated-credential create'))->toBe([])
        ->and($this->azure->state()['github']['secrets'])->toBe([]);
});

test('without the resource group it asks for provisioning first', function () {
    $this->azure->modify(function (array $state) {
        $state['groups'] = [];

        return $state;
    });

    $result = oidc($this);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('azure-provision.sh')
        ->and($this->azure->calls('az ad app create'))->toBe([]);
});

test('not being signed in to Azure stops it with the way to fix it', function () {
    $this->azure->seed(['account' => ['logged_in' => false]]);

    $result = oidc($this);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('az login')
        ->and($this->azure->calls('az ad app create'))->toBe([]);
});

test('several registrations with the name are refused', function () {
    $this->azure->seed(['ad_apps' => [
        ['appId' => 'aaaaaaaa-0000-4000-8000-000000000001', 'displayName' => 'acme-tracker-deploy', 'credentials' => [], 'federated' => []],
        ['appId' => 'aaaaaaaa-0000-4000-8000-000000000002', 'displayName' => 'acme-tracker-deploy', 'credentials' => [], 'federated' => []],
    ]]);

    $result = oidc($this);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('Several app registrations')
        ->and($this->azure->calls('az role assignment create'))->toBe([]);
});

test('without a repository to read from it asks for one', function () {
    Process::path($this->azure->work)->run('git remote remove origin')->throw();

    $result = oidc($this, ['APP_NAME' => 'acme-tracker']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('GITHUB_OWNER and GITHUB_REPO');
});

test('the owner and repository can be given instead of read from the remote', function () {
    $result = oidc($this, ['GITHUB_OWNER' => 'someone', 'GITHUB_REPO' => 'acme-tracker']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(oidcApp($this)['federated']['github-main']['subject'])->toBe('repo:someone/acme-tracker:ref:refs/heads/main')
        ->and($this->azure->calls('gh secret set AZURE_CLIENT_ID --repo someone/acme-tracker'))->toHaveCount(1);
});

test('the next steps say how to deploy by hand', function () {
    $result = oidc($this);

    expect($result->output())->toContain('gh workflow run deploy.yml --repo acme/acme-tracker --ref main')
        ->and($result->output())->toContain('AADSTS700213');
});
