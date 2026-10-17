<?php

use Tests\Support\AzureHarness;

/*
 * deploy/azure-teardown.sh deletes everything an app created in Azure. These tests run
 * the real script with stand-ins for az and gh (see tests/Support), so nothing reaches
 * Azure or GitHub, and they check above all that it deletes only what it should.
 */

beforeEach(function () {
    $this->azure = new AzureHarness;
});

afterEach(function () {
    $this->azure->cleanup();
});

/** A fully set up app: the resources, the budget, both registrations, and the repository secrets. */
function teardownApp(object $test, array $extra = []): void
{
    $test->azure->provisioned(array_replace_recursive([
        'budgets' => ['rg-acme-tracker/acme-tracker-monthly' => ['properties' => ['amount' => 1]]],
        'ad_apps' => [
            ['appId' => 'aaaaaaaa-0000-4000-8000-000000000001', 'displayName' => 'acme-tracker-deploy', 'credentials' => [], 'federated' => []],
            ['appId' => 'aaaaaaaa-0000-4000-8000-000000000002', 'displayName' => 'acme-tracker-easyauth', 'credentials' => [], 'federated' => []],
        ],
        'github' => [
            'secrets' => ['AZURE_CLIENT_ID' => 'x', 'AZURE_TENANT_ID' => 'x', 'AZURE_SUBSCRIPTION_ID' => 'x', 'UNRELATED_SECRET' => 'keep'],
            'variables' => ['DEPLOY_ON_MERGE' => 'true', 'UNRELATED_VARIABLE' => 'keep'],
        ],
    ], $extra));
}

function teardown(object $test, array $env = [], string $input = '')
{
    return $test->azure->run('azure-teardown.sh', $env, $input);
}

function teardownConfirmed(object $test, array $env = [])
{
    return teardown($test, $env, "acme-tracker\n");
}

test('a dry run lists exactly what would be removed and deletes nothing', function () {
    teardownApp($this);
    $before = $this->azure->state();

    $result = teardown($this, ['DRY_RUN' => '1']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('Resource group rg-acme-tracker (uksouth) and everything in it')
        ->toContain('acme-tracker  (Microsoft.App/containerApps)')
        ->toContain('acme-tracker-env  (Microsoft.App/managedEnvironments)')
        ->toContain('acme-tracker-sql-1234  (Microsoft.Sql/servers)')
        ->toContain('acme-tracker-monthly  (budget alert, deleted first)')
        ->toContain('acme-tracker-deploy  (aaaaaaaa-0000-4000-8000-000000000001)')
        ->toContain('acme-tracker-easyauth  (aaaaaaaa-0000-4000-8000-000000000002)')
        ->toContain('- AZURE_CLIENT_ID')
        ->toContain('- DEPLOY_ON_MERGE')
        ->toContain('Dry run: nothing was deleted.')
        ->and($this->azure->state())->toBe($before)
        ->and($this->azure->calls('az group delete'))->toBe([])
        ->and($this->azure->calls('az ad app delete'))->toBe([])
        ->and($this->azure->calls('gh secret delete'))->toBe([]);
});

test('it deletes nothing unless the app name is typed', function (string $input) {
    teardownApp($this);
    $before = $this->azure->state();

    $result = teardown($this, [], $input);

    expect($result->failed())->toBeTrue()
        ->and($result->output())->toContain('Aborted: nothing was deleted.')
        ->and($this->azure->state())->toBe($before);
})->with([
    'a wrong name' => ["nope\n"],
    'the name with other text' => ["acme-tracker please\n"],
    'a different case' => ["Acme-Tracker\n"],
    'an empty answer' => ["\n"],
    'no answer at all' => [''],
]);

test('typing the app name removes everything it created', function () {
    teardownApp($this);

    $result = teardownConfirmed($this);
    $state = $this->azure->state();

    expect($result->successful())->toBeTrue($result->errorOutput().$result->output())
        ->and($state['groups'])->toBe([])
        ->and($state['apps'])->toBe([])
        ->and($state['sql_servers'])->toBe([])
        ->and($state['envs'])->toBe([])
        ->and($state['budgets'])->toBe([])
        ->and($state['ad_apps'])->toBe([])
        ->and($state['github']['secrets'])->toBe(['UNRELATED_SECRET' => 'keep'])
        ->and($state['github']['variables'])->toBe(['UNRELATED_VARIABLE' => 'keep'])
        ->and($result->output())->toContain('gone');
});

test('deploy on merge is turned off first, then the budget, the resources, the registrations and the secrets', function () {
    teardownApp($this);

    teardownConfirmed($this);

    $order = [
        $this->azure->position('gh variable delete DEPLOY_ON_MERGE'),
        $this->azure->position('az rest --method delete'),
        $this->azure->position('az group delete'),
        $this->azure->position('az ad app delete'),
        $this->azure->position('gh secret delete'),
    ];

    expect($order)->each->not->toBeFalse()
        ->and($order)->toBe(collect($order)->sort()->values()->all());
});

test('only the two registrations named after the app are deleted', function () {
    teardownApp($this, ['ad_apps' => [
        2 => ['appId' => 'aaaaaaaa-0000-4000-8000-000000000003', 'displayName' => 'someone-elses-deploy', 'credentials' => [], 'federated' => []],
        3 => ['appId' => 'aaaaaaaa-0000-4000-8000-000000000004', 'displayName' => 'acme-tracker-deploy-old', 'credentials' => [], 'federated' => []],
    ]]);

    teardownConfirmed($this);

    expect(collect($this->azure->state()['ad_apps'])->pluck('displayName')->all())
        ->toBe(['someone-elses-deploy', 'acme-tracker-deploy-old'])
        ->and($this->azure->state()['deleted_apps'])->toBe(['aaaaaaaa-0000-4000-8000-000000000001', 'aaaaaaaa-0000-4000-8000-000000000002']);
});

test('only the three Azure secrets and the one variable are removed, never others', function () {
    teardownApp($this);

    teardownConfirmed($this);

    expect(collect($this->azure->calls('gh secret delete'))->map(fn (string $call) => explode(' ', $call)[3])->all())
        ->toBe(['AZURE_CLIENT_ID', 'AZURE_TENANT_ID', 'AZURE_SUBSCRIPTION_ID'])
        ->and($this->azure->calls('gh variable delete'))->toHaveCount(1);
});

test('only the app\'s own resource group is touched', function () {
    teardownApp($this, [
        'groups' => ['rg-other' => ['location' => 'uksouth']],
        'apps' => ['other' => ['group' => 'rg-other', 'fqdn' => 'other.example.io', 'revision' => 'other--1', 'secrets' => [], 'env_vars' => [], 'auth' => []]],
    ]);

    teardownConfirmed($this);
    $state = $this->azure->state();

    expect($state['groups'])->toHaveKey('rg-other')
        ->and($state['groups'])->not->toHaveKey('rg-acme-tracker')
        ->and($state['apps'])->toHaveKey('other')
        ->and($this->azure->calls('az group delete'))->toBe(['az group delete --name rg-acme-tracker --yes']);
});

test('FORCE skips the question', function () {
    teardownApp($this);

    $result = teardown($this, ['FORCE' => '1']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->not->toContain('Type the app name')
        ->and($this->azure->state()['groups'])->toBe([]);
});

test('NO_WAIT returns without waiting for the group to finish', function () {
    teardownApp($this);

    $result = teardown($this, ['FORCE' => '1', 'NO_WAIT' => '1']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($this->azure->calls('az group delete'))->toBe(['az group delete --name rg-acme-tracker --yes --no-wait'])
        ->and($this->azure->calls('az group exists'))->toBe([])
        ->and($result->output())->toContain('not waiting for it to finish');
});

test('a group that is still there afterwards is warned about', function () {
    teardownApp($this);

    $result = teardown($this, ['FORCE' => '1', 'FAKE_GROUP_STAYS' => '1']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->errorOutput())->toContain('rg-acme-tracker still exists');
});

test('a group that holds nothing this project creates is refused, even with FORCE', function () {
    $this->azure->seed([
        'groups' => ['rg-acme-tracker' => ['location' => 'uksouth']],
        'extra_resources' => ['rg-acme-tracker' => [['payroll-database', 'Microsoft.Sql/servers'], ['finance-vm', 'Microsoft.Compute/virtualMachines']]],
    ]);
    $before = $this->azure->state();

    $result = teardown($this, ['FORCE' => '1']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('contains nothing this project creates for acme-tracker')
        ->and($result->errorOutput())->toContain('payroll-database (Microsoft.Sql/servers)')
        ->and($result->errorOutput())->toContain('finance-vm (Microsoft.Compute/virtualMachines)')
        ->and($result->errorOutput())->toContain('Nothing was deleted')
        ->and($this->azure->state())->toBe($before)
        ->and($this->azure->calls('az group delete'))->toBe([]);
});

test('a group that also holds something else is deleted, but the extra resource is flagged first', function () {
    teardownApp($this, ['extra_resources' => ['rg-acme-tracker' => [['stray-storage', 'Microsoft.Storage/storageAccounts']]]]);

    $dry = teardown($this, ['DRY_RUN' => '1']);

    expect($dry->output())->toContain('stray-storage  (Microsoft.Storage/storageAccounts)  <- not created by azure-provision.sh, but it is in this group');

    $result = teardownConfirmed($this);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($this->azure->state()['groups'])->toBe([]);
});

test('a group with nothing in it is deleted', function () {
    $this->azure->seed(['groups' => ['rg-acme-tracker' => ['location' => 'uksouth']]]);

    $result = teardownConfirmed($this);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('(empty)')
        ->and($this->azure->state()['groups'])->toBe([]);
});

test('the repository secrets belong to the app named after the repository, so another app leaves them alone', function () {
    teardownApp($this, [
        'groups' => ['rg-other-app' => ['location' => 'uksouth']],
        'apps' => ['other-app' => ['group' => 'rg-other-app', 'fqdn' => 'other-app.example.io', 'revision' => 'other-app--1', 'secrets' => [], 'env_vars' => [], 'auth' => []]],
    ]);

    $result = teardown($this, ['APP_NAME' => 'other-app', 'FORCE' => '1']);
    $state = $this->azure->state();

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('they belong to the app named after the repository (acme-tracker), not other-app')
        ->and($state['groups'])->not->toHaveKey('rg-other-app')
        ->and($state['groups'])->toHaveKey('rg-acme-tracker')
        ->and($state['github']['secrets'])->toHaveKey('AZURE_CLIENT_ID')
        ->and($state['github']['variables'])->toHaveKey('DEPLOY_ON_MERGE')
        ->and($this->azure->calls('gh secret delete'))->toBe([])
        ->and($this->azure->calls('gh variable delete'))->toBe([]);
});

test('REMOVE_SECRETS=false leaves the secrets and the variable alone', function () {
    teardownApp($this);

    $result = teardown($this, ['FORCE' => '1', 'REMOVE_SECRETS' => 'false']);
    $state = $this->azure->state();

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('left alone (REMOVE_SECRETS is off)')
        ->and($state['github']['secrets'])->toHaveKey('AZURE_CLIENT_ID')
        ->and($state['github']['variables'])->toHaveKey('DEPLOY_ON_MERGE')
        ->and($state['groups'])->toBe([]);
});

test('with nothing left it says so and does nothing, and a second run is the same', function () {
    teardownApp($this);
    teardownConfirmed($this);
    $deletes = count($this->azure->calls('az group delete'));

    $second = teardownConfirmed($this);

    expect($second->successful())->toBeTrue($second->errorOutput())
        ->and($second->output())->toContain('does not exist (already gone)')
        ->and($second->output())->toContain('Nothing to remove.')
        ->and($this->azure->calls('az group delete'))->toHaveCount($deletes);
});

test('when GitHub cannot be reached the rest is removed and the secrets are left to do by hand', function () {
    teardownApp($this);

    $result = teardown($this, ['FORCE' => '1', 'FAKE_FAIL' => 'gh']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('could not be read')
        ->and($result->output())->toContain('Remove these by hand')
        ->and($this->azure->state()['groups'])->toBe([])
        ->and($this->azure->state()['github']['secrets'])->toHaveKey('AZURE_CLIENT_ID');
});

test('when the variable cannot be listed the secrets are still removed and the variable is left to do by hand', function () {
    teardownApp($this);

    $result = teardown($this, ['FORCE' => '1', 'FAKE_FAIL' => 'gh variable list']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('Remove these by hand')
        ->and($this->azure->state()['github']['secrets'])->toBe(['UNRELATED_SECRET' => 'keep'])
        ->and($this->azure->state()['github']['variables'])->toHaveKey('DEPLOY_ON_MERGE');
});

test('a secret that cannot be deleted is reported and the rest carries on', function () {
    teardownApp($this);

    $result = teardown($this, ['FORCE' => '1', 'FAKE_FAIL' => 'gh secret delete']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('could not delete AZURE_CLIENT_ID')
        ->and($result->output())->toContain('Remove these by hand')
        ->and($this->azure->state()['groups'])->toBe([])
        ->and($this->azure->state()['ad_apps'])->toBe([]);
});

test('a variable that cannot be deleted is reported, and the group is still removed', function () {
    teardownApp($this);

    $result = teardown($this, ['FORCE' => '1', 'FAKE_FAIL' => 'gh variable delete']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('could not delete DEPLOY_ON_MERGE')
        ->and($this->azure->state()['groups'])->toBe([]);
});

test('a budget that cannot be deleted is warned about, and the group is still removed', function () {
    teardownApp($this);

    $result = teardown($this, ['FORCE' => '1', 'FAKE_FAIL' => 'az rest delete']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->errorOutput())->toContain('could not delete the budget alert acme-tracker-monthly')
        ->and($this->azure->state()['groups'])->toBe([]);
});

test('not being signed in to Azure stops it before anything is listed or deleted', function () {
    teardownApp($this);

    $this->azure->seed(['account' => ['logged_in' => false]]);

    $result = teardown($this, ['FORCE' => '1']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('az login')
        ->and($this->azure->calls('az group delete'))->toBe([])
        ->and($this->azure->calls('az group show'))->toBe([]);
});

test('a name Azure cannot use is refused before Azure is called', function () {
    $result = teardown($this, ['APP_NAME' => 'Bad_Name', 'FORCE' => '1']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('is not valid')
        ->and($this->azure->log())->toBe([]);
});

test('the app name can be given instead of read from the repository', function () {
    $this->azure->seed([
        'groups' => ['rg-given-app' => ['location' => 'uksouth']],
        'apps' => ['given-app' => ['group' => 'rg-given-app', 'fqdn' => 'given-app.example.io', 'revision' => 'given-app--1', 'secrets' => [], 'env_vars' => [], 'auth' => []]],
    ]);

    $result = teardown($this, ['APP_NAME' => 'given-app', 'FORCE' => '1']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($this->azure->state()['groups'])->toBe([]);
});
