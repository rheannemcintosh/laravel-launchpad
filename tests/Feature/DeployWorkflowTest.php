<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Yaml\Yaml;

/*
 * The deploy workflow builds and publishes the image, then updates the app. Updating
 * the app needs the Azure sign-in that the OIDC setup script sets up AFTER the first
 * image exists, so a run before that must build and push the image and finish green with
 * a notice, not fail. A merge that should update the app but cannot is a real mistake and
 * must still fail. These tests run the check step's own shell script under every
 * combination, and check the shape of the workflow around it.
 */

function deployWorkflow(): array
{
    return Yaml::parse(File::get(base_path('.github/workflows/deploy.yml')));
}

function deploySteps(): array
{
    return deployWorkflow()['jobs']['deploy']['steps'];
}

function deployStep(string $name): array
{
    foreach (deploySteps() as $step) {
        if (($step['name'] ?? null) === $name) {
            return $step;
        }
    }

    throw new RuntimeException("No step named {$name}");
}

/** Runs the check step's script the way GitHub does (bash -e), and returns what it printed and wrote. */
function runAzureCheck(string $event, array $secrets): array
{
    $directory = sys_get_temp_dir().'/deploy-check-'.uniqid();
    File::ensureDirectoryExists($directory);

    $result = Process::path($directory)
        ->env([
            'GITHUB_OUTPUT' => "{$directory}/output",
            'GITHUB_STEP_SUMMARY' => "{$directory}/summary",
            'EVENT' => $event,
            'AZURE_CLIENT_ID' => $secrets['AZURE_CLIENT_ID'] ?? '',
            'AZURE_TENANT_ID' => $secrets['AZURE_TENANT_ID'] ?? '',
            'AZURE_SUBSCRIPTION_ID' => $secrets['AZURE_SUBSCRIPTION_ID'] ?? '',
        ])
        ->run([(new ExecutableFinder)->find('bash'), '-e', '-c', deployStep('Check the Azure sign-in is set up')['run']]);

    $output = [
        'exit' => $result->exitCode(),
        'stdout' => $result->output(),
        'stderr' => $result->errorOutput(),
        'ready' => null,
        'summary' => File::exists("{$directory}/summary") ? File::get("{$directory}/summary") : '',
    ];

    if (File::exists("{$directory}/output") && preg_match('/^ready=(\w+)$/m', File::get("{$directory}/output"), $match)) {
        $output['ready'] = $match[1];
    }

    File::deleteDirectory($directory);

    return $output;
}

const DEPLOY_ALL_SECRETS = [
    'AZURE_CLIENT_ID' => '11111111-1111-4111-8111-111111111111',
    'AZURE_TENANT_ID' => '22222222-2222-4222-8222-222222222222',
    'AZURE_SUBSCRIPTION_ID' => '33333333-3333-4333-8333-333333333333',
];

test('with all three secrets set, a run goes on to update the app, with no notice', function (string $event) {
    $check = runAzureCheck($event, DEPLOY_ALL_SECRETS);

    expect($check['exit'])->toBe(0)
        ->and($check['ready'])->toBe('true')
        ->and($check['stdout'])->not->toContain('::notice')
        ->and($check['summary'])->toBe('');
})->with(['a manual run' => ['workflow_dispatch'], 'a merge' => ['push']]);

test('a manual run before the Azure sign-in exists finishes green, says why, and does not update the app', function () {
    $check = runAzureCheck('workflow_dispatch', []);

    expect($check['exit'])->toBe(0)
        ->and($check['ready'])->toBe('false')
        ->and($check['stdout'])->toContain('::notice title=Azure sign-in not set up yet::')
        ->and($check['stdout'])->toContain('The image is built and pushed, but the app was not updated')
        ->and($check['stdout'])->toContain('missing secrets: AZURE_CLIENT_ID, AZURE_TENANT_ID, AZURE_SUBSCRIPTION_ID')
        ->and($check['stdout'])->toContain('./deploy/azure-oidc-setup.sh')
        ->and($check['summary'])->toContain('Azure sign-in not set up yet')
        ->and($check['stdout'])->not->toContain('::error');
});

test('a manual run names only the secrets that are missing', function (array $set, string $missing) {
    $check = runAzureCheck('workflow_dispatch', array_intersect_key(DEPLOY_ALL_SECRETS, array_flip($set)));

    expect($check['exit'])->toBe(0)
        ->and($check['ready'])->toBe('false')
        ->and($check['stdout'])->toContain("missing secrets: {$missing})");
})->with([
    'only the client ID' => [['AZURE_CLIENT_ID'], 'AZURE_TENANT_ID, AZURE_SUBSCRIPTION_ID'],
    'only the tenant ID' => [['AZURE_TENANT_ID'], 'AZURE_CLIENT_ID, AZURE_SUBSCRIPTION_ID'],
    'only the subscription ID' => [['AZURE_SUBSCRIPTION_ID'], 'AZURE_CLIENT_ID, AZURE_TENANT_ID'],
    'client ID and tenant ID' => [['AZURE_CLIENT_ID', 'AZURE_TENANT_ID'], 'AZURE_SUBSCRIPTION_ID'],
    'client ID and subscription ID' => [['AZURE_CLIENT_ID', 'AZURE_SUBSCRIPTION_ID'], 'AZURE_TENANT_ID'],
    'tenant ID and subscription ID' => [['AZURE_TENANT_ID', 'AZURE_SUBSCRIPTION_ID'], 'AZURE_CLIENT_ID'],
]);

test('a merge that should update the app but has no Azure sign-in fails clearly, so a mistake is never silent', function (array $set) {
    $check = runAzureCheck('push', array_intersect_key(DEPLOY_ALL_SECRETS, array_flip($set)));

    expect($check['exit'])->toBe(1)
        ->and($check['ready'])->toBeNull()
        ->and($check['stdout'])->toContain('::error title=Azure sign-in is not set up::')
        ->and($check['stdout'])->toContain('DEPLOY_ON_MERGE is on')
        ->and($check['stdout'])->toContain('./deploy/azure-oidc-setup.sh')
        ->and($check['stdout'])->toContain('delete the DEPLOY_ON_MERGE variable');
})->with([
    'no secrets' => [[]],
    'one secret' => [['AZURE_CLIENT_ID']],
    'two secrets' => [['AZURE_CLIENT_ID', 'AZURE_TENANT_ID']],
]);

test('the check comes before the image is built, so a mistake on a merge fails before any build time is spent', function () {
    $names = array_column(deploySteps(), 'name');

    expect(array_search('Check the Azure sign-in is set up', $names))
        ->toBeLessThan(array_search('Build and push image', $names));
});

test('building and publishing the image never depends on the Azure sign-in', function () {
    foreach (['Checkout code', 'Derive names', 'Log in to GitHub Container Registry', 'Set up Buildx', 'Build and push image'] as $name) {
        expect(deployStep($name))->not->toHaveKey('if');
    }

    expect(deployStep('Build and push image')['with']['push'])->toBeTrue();
});

test('every step that uses Azure runs only when the sign-in is set up', function () {
    $azure = collect(deploySteps())->filter(fn (array $step) => str_starts_with($step['uses'] ?? '', 'azure/'));

    expect($azure)->not->toBeEmpty();

    foreach ($azure as $step) {
        expect($step['if'] ?? null)->toBe("steps.azure.outputs.ready == 'true'", "{$step['name']} must wait for the sign-in check");
    }

    expect(array_column($azure->values()->all(), 'name'))->toBe(['Azure login (OIDC)', 'Deploy to Container Apps']);
});

test('the workflow still starts as it did, and a merge still needs the opt-in', function () {
    $workflow = deployWorkflow();
    // YAML reads the key "on" as true.
    $triggers = $workflow['on'] ?? $workflow[true];

    expect($triggers)->toHaveKeys(['push', 'workflow_dispatch'])
        ->and($triggers['push']['branches'])->toBe(['main'])
        ->and($workflow['jobs']['deploy']['if'])->toBe("github.event_name == 'workflow_dispatch' || vars.DEPLOY_ON_MERGE == 'true'");
});

test('the check reads the three secrets the Azure login uses', function () {
    $env = deployStep('Check the Azure sign-in is set up')['env'];
    $login = deployStep('Azure login (OIDC)')['with'];

    expect($env['AZURE_CLIENT_ID'])->toBe($login['client-id'])
        ->and($env['AZURE_TENANT_ID'])->toBe($login['tenant-id'])
        ->and($env['AZURE_SUBSCRIPTION_ID'])->toBe($login['subscription-id']);
});
