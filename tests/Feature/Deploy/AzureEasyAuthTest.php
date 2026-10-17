<?php

use Tests\Support\AzureHarness;

/*
 * deploy/azure-easyauth-setup.sh puts Microsoft sign-in in front of the app and lets
 * only one user in. These tests run the real script with stand-ins for az and curl
 * (see tests/Support), so nothing reaches Azure.
 */

const EASYAUTH_USER = '00000000-0000-4000-8000-0000000000aa';
const EASYAUTH_TENANT = '00000000-0000-4000-8000-000000000002';
const EASYAUTH_ADDRESS = 'acme-tracker.fake.uksouth.azurecontainerapps.io';

beforeEach(function () {
    $this->azure = new AzureHarness;
    $this->azure->provisioned();
});

afterEach(function () {
    $this->azure->cleanup();
});

function easyauth(object $test, array $env = [])
{
    return $test->azure->run('azure-easyauth-setup.sh', $env);
}

function easyauthApp(object $test): ?array
{
    return collect($test->azure->state()['ad_apps'])->firstWhere('displayName', 'acme-tracker-easyauth');
}

function easyauthAssigned(object $test): array
{
    $app = easyauthApp($test);
    $servicePrincipal = $test->azure->state()['sps'][$app['appId']]['id'];

    return $test->azure->state()['sps_by_object'][$servicePrincipal]['assigned'] ?? [];
}

test('a first run creates the sign-in for this directory only, with the right redirect address', function () {
    $result = easyauth($this);
    $app = easyauthApp($this);

    expect($result->successful())->toBeTrue($result->errorOutput().$result->output())
        ->and($app)->not->toBeNull()
        ->and($app['audience'])->toBe('AzureADMyOrg')
        ->and($app['redirects'])->toBe(['https://'.EASYAUTH_ADDRESS.'/.auth/login/aad/callback'])
        ->and($app['id_tokens'])->toBeTrue()
        ->and($this->azure->state()['sps'])->toHaveKey($app['appId']);
});

test('only assigned users may sign in, and only the signed-in user is assigned', function () {
    $result = easyauth($this);
    $app = easyauthApp($this);

    expect($this->azure->state()['sps'][$app['appId']]['assignment_required'])->toBeTrue()
        ->and(easyauthAssigned($this))->toBe([EASYAUTH_USER])
        ->and($result->output())->toContain('Allowed user     : '.EASYAUTH_USER);
});

test('access is restricted before visitors are redirected, so no account can get in at any moment', function () {
    easyauth($this);

    $required = $this->azure->position('az ad sp update');
    $assigned = $this->azure->position('az rest --method post');
    $redirect = $this->azure->position('az containerapp auth update');

    expect($required)->not->toBeFalse()
        ->and($assigned)->not->toBeFalse()
        ->and($redirect)->not->toBeFalse()
        ->and($required)->toBeLessThan($redirect)
        ->and($assigned)->toBeLessThan($redirect);
});

test('the app is connected to Microsoft sign-in and redirects visitors', function () {
    easyauth($this);
    $app = easyauthApp($this);
    $container = $this->azure->state()['apps']['acme-tracker'];

    expect($container['auth']['provider'])->toBe([
        'client_id' => $app['appId'],
        'issuer' => 'https://login.microsoftonline.com/'.EASYAUTH_TENANT.'/v2.0',
    ])->and($container['auth']['enabled'])->toBeTrue()
        ->and($container['auth']['action'])->toBe('RedirectToLoginPage')
        ->and($container['auth']['redirect_provider'])->toBe('azureactivedirectory');
});

test('no token store is asked for, because Container Apps only supports one backed by storage', function () {
    easyauth($this);

    expect($this->azure->calls('az containerapp auth update')[0])->not->toContain('--token-store')
        ->and($this->azure->state()['apps']['acme-tracker']['auth']['token_store'])->toBeFalse();
});

test('a client secret is created for two years, stored in the app and never printed', function () {
    $result = easyauth($this);
    $app = easyauthApp($this);
    $credential = $app['credentials'][0];

    expect($app['credentials'])->toHaveCount(1)
        ->and($credential['displayName'])->toBe('easyauth')
        ->and(substr($credential['endDateTime'], 0, 4))->toBe((string) (date('Y') + 2))
        ->and($app['append'])->toBeTrue()
        ->and($this->azure->state()['apps']['acme-tracker']['secrets']['microsoft-provider-authentication-secret'])->toBe($credential['password'])
        ->and($result->output().$result->errorOutput())->not->toContain($credential['password'])
        ->and($result->output())->toContain('expires '.substr($credential['endDateTime'], 0, 10));
});

test('the app is restarted so it picks up a new secret', function () {
    $result = easyauth($this);

    expect($this->azure->state()['restarts'])->toBe(['acme-tracker--0000001'])
        ->and($result->output())->toContain('Restarting the app');
});

test('visitors are checked the way a browser asks, and the redirect is confirmed', function () {
    $result = easyauth($this);
    $call = $this->azure->calls('curl')[0];

    expect($call)->toContain('-A Mozilla/5.0')
        ->and($call)->toContain('Accept: text/html')
        ->and($call)->toContain('https://'.EASYAUTH_ADDRESS.'/')
        ->and($result->output())->toContain('Redirect check   : visitors are sent to Microsoft sign-in');
});

test('running it again reuses everything: no new secret, no second assignment, no restart', function () {
    easyauth($this);

    $second = easyauth($this);
    $app = easyauthApp($this);

    expect($second->successful())->toBeTrue($second->errorOutput())
        ->and($second->output())->toContain('reusing')
        ->and($app['credentials'])->toHaveCount(1)
        ->and(collect($this->azure->state()['ad_apps']))->toHaveCount(1)
        ->and(easyauthAssigned($this))->toBe([EASYAUTH_USER])
        ->and($this->azure->state()['restarts'])->toHaveCount(1)
        ->and($this->azure->calls('az ad app credential reset'))->toHaveCount(1)
        ->and($this->azure->calls('az rest --method post'))->toHaveCount(1)
        ->and($this->azure->calls('az containerapp auth microsoft update')[1])->toContain('--client-secret-name microsoft-provider-authentication-secret');
});

test('rotating issues a new secret, removes the old one and restarts the app', function () {
    easyauth($this);
    $old = easyauthApp($this)['credentials'][0];

    $result = easyauth($this, ['ROTATE_SECRET' => '1']);
    $credentials = easyauthApp($this)['credentials'];

    expect($result->output())->toContain('rotating')
        ->and($result->output())->toContain("removed the previous secret {$old['keyId']}")
        ->and($credentials)->toHaveCount(1)
        ->and($credentials[0]['keyId'])->not->toBe($old['keyId'])
        ->and($this->azure->state()['apps']['acme-tracker']['secrets']['microsoft-provider-authentication-secret'])->toBe($credentials[0]['password'])
        ->and($this->azure->state()['restarts'])->toHaveCount(2);
});

test('a secret the app has lost is replaced, because it cannot be read back', function () {
    easyauth($this);
    $this->azure->modify(function (array $state) {
        unset($state['apps']['acme-tracker']['secrets']['microsoft-provider-authentication-secret']);

        return $state;
    });

    $result = easyauth($this);

    expect($result->output())->toContain('the container app has no copy of the secret')
        ->and(easyauthApp($this)['credentials'])->toHaveCount(1)
        ->and($this->azure->state()['apps']['acme-tracker']['secrets'])->toHaveKey('microsoft-provider-authentication-secret');
});

test('a run that stopped before the redirect was switched on is finished, with a restart', function () {
    easyauth($this);
    $this->azure->modify(function (array $state) {
        $state['apps']['acme-tracker']['auth']['action'] = 'Return403';
        $state['restarts'] = [];

        return $state;
    });

    easyauth($this);

    expect($this->azure->state()['apps']['acme-tracker']['auth']['action'])->toBe('RedirectToLoginPage')
        ->and($this->azure->state()['restarts'])->toHaveCount(1)
        ->and(easyauthApp($this)['credentials'])->toHaveCount(1);
});

test('only what has drifted is put right, and what was added by hand is kept', function () {
    easyauth($this);
    $this->azure->modify(function (array $state) {
        $index = array_key_first(array_filter($state['ad_apps'], fn (array $app) => $app['displayName'] === 'acme-tracker-easyauth'));
        $state['ad_apps'][$index]['redirects'] = ['https://added.by.hand/callback'];
        $state['ad_apps'][$index]['id_tokens'] = false;
        $state['ad_apps'][$index]['audience'] = 'AzureADandPersonalMicrosoftAccount';

        return $state;
    });

    $result = easyauth($this);
    $app = easyauthApp($this);

    expect($app['redirects'])->toBe(['https://added.by.hand/callback', 'https://'.EASYAUTH_ADDRESS.'/.auth/login/aad/callback'])
        ->and($app['id_tokens'])->toBeTrue()
        ->and($app['audience'])->toBe('AzureADMyOrg')
        ->and($result->output())->toContain('adding the redirect address')
        ->toContain('turning ID tokens on')
        ->toContain('limiting it to this directory');
});

test('other users who are assigned are reported and left alone', function () {
    easyauth($this);
    $app = easyauthApp($this);
    $servicePrincipal = $this->azure->state()['sps'][$app['appId']]['id'];
    $this->azure->modify(function (array $state) use ($servicePrincipal) {
        $state['sps_by_object'][$servicePrincipal]['assigned'][] = '00000000-0000-4000-8000-0000000000bb';

        return $state;
    });

    $result = easyauth($this);

    expect($result->output())->toContain('NOTE: 1 other user(s) or group(s) are also assigned')
        ->and(easyauthAssigned($this))->toBe([EASYAUTH_USER, '00000000-0000-4000-8000-0000000000bb']);
});

test('a different allowed user can be given', function () {
    $other = '00000000-0000-4000-8000-0000000000cc';

    $result = easyauth($this, ['ALLOWED_USER_ID' => $other]);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(easyauthAssigned($this))->toBe([$other])
        ->and($this->azure->calls('az ad signed-in-user show'))->toBe([]);
});

test('an allowed user that is not an object ID is refused before anything is created', function () {
    $result = easyauth($this, ['ALLOWED_USER_ID' => 'me@example.com']);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('is not an object ID')
        ->and($this->azure->calls('az ad app create'))->toBe([])
        ->and($this->azure->calls('az containerapp auth'))->toBe([]);
});

test('without the container app it asks for provisioning first', function () {
    $this->azure->modify(function (array $state) {
        $state['apps'] = [];

        return $state;
    });

    $result = easyauth($this);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('azure-provision.sh')
        ->and($this->azure->calls('az ad app create'))->toBe([]);
});

test('not being signed in to Azure stops it with the way to fix it', function () {
    $this->azure->seed(['account' => ['logged_in' => false]]);

    $result = easyauth($this);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('az login')
        ->and($this->azure->calls('az ad app create'))->toBe([]);
});

test('several registrations with the name are refused', function () {
    $this->azure->seed(['ad_apps' => [
        ['appId' => 'aaaaaaaa-0000-4000-8000-000000000001', 'displayName' => 'acme-tracker-easyauth', 'credentials' => [], 'federated' => []],
        ['appId' => 'aaaaaaaa-0000-4000-8000-000000000002', 'displayName' => 'acme-tracker-easyauth', 'credentials' => [], 'federated' => []],
    ]]);

    $result = easyauth($this);

    expect($result->failed())->toBeTrue()
        ->and($result->errorOutput())->toContain('Several app registrations')
        ->and($this->azure->calls('az containerapp auth'))->toBe([]);
});

test('when the settings take a moment to apply it waits and checks again', function () {
    $this->azure->seed(['curl_not_yet' => 2]);

    $result = easyauth($this);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('not yet (got: 403 ), waiting (2/6)')
        ->and($this->azure->calls('curl'))->toHaveCount(3)
        ->and($result->output())->toContain('Redirect check   : visitors are sent to Microsoft sign-in');
});

test('a redirect that is never confirmed is reported without failing the run', function () {
    $result = easyauth($this, ['FAKE_CURL_NEVER' => '1']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($this->azure->calls('curl'))->toHaveCount(6)
        ->and($result->output())->toContain('NOT confirmed yet')
        ->and($result->output())->not->toContain('Redirect check   : visitors are sent to Microsoft sign-in');
});

test('the redirect check can be skipped', function () {
    $result = easyauth($this, ['VERIFY' => 'false']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($this->azure->calls('curl'))->toBe([])
        ->and($result->output())->not->toContain('Redirect check');
});

test('a restart that fails is reported with the command to run by hand, and the run still succeeds', function () {
    $result = easyauth($this, ['FAKE_FAIL' => 'az containerapp revision restart']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->errorOutput())->toContain('Could not restart acme-tracker--0000001')
        ->and($result->errorOutput())->toContain('az containerapp revision restart -g rg-acme-tracker -n acme-tracker --revision acme-tracker--0000001');
});

test('a secret that runs out within 60 days is warned about', function () {
    easyauth($this);
    $soon = gmdate('Y-m-d\TH:i:s\Z', strtotime('+10 days'));
    $this->azure->modify(function (array $state) use ($soon) {
        foreach ($state['ad_apps'] as $index => $app) {
            if ($app['displayName'] === 'acme-tracker-easyauth') {
                $state['ad_apps'][$index]['credentials'][0]['endDateTime'] = $soon;
            }
        }

        return $state;
    });

    $result = easyauth($this);

    expect($result->output())->toContain('WARNING: the client secret expires on '.substr($soon, 0, 10))
        ->toContain('ROTATE_SECRET=1');
});

test('a secret with plenty of time left is not warned about', function () {
    $result = easyauth($this);

    expect($result->output())->not->toContain('WARNING');
});
