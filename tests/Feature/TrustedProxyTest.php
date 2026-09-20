<?php

/*
 * Azure Container Apps terminates HTTPS at its ingress and passes plain HTTP to the
 * app, adding X-Forwarded-* headers. Unless the app trusts those headers it thinks
 * every visit was insecure, so it builds http:// asset addresses on an https:// page
 * and the browser blocks them as mixed content.
 *
 * These tests send the request the way the ingress does: plain HTTP to a host with
 * no port, plus the forwarded headers.
 */

const PROXIED_URL = 'http://app.example.test/';

function viaAzureIngress(): array
{
    return [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Port' => '443',
    ];
}

test('builds https addresses when the proxy says the visit was https', function () {
    $response = $this->withHeaders(viaAzureIngress())->get(PROXIED_URL);

    $response->assertOk();

    expect($response->getContent())
        ->toContain('https://app.example.test/build/assets/')
        ->not->toContain('http://app.example.test/build/assets/');
});

test('also builds https addresses in the preload Link header', function () {
    $response = $this->withHeaders(viaAzureIngress())->get(PROXIED_URL);

    expect($response->headers->get('Link'))
        ->toContain('https://app.example.test/build/assets/')
        ->not->toContain('http://app.example.test/build/assets/');
});

test('keeps http addresses when nothing says the visit was https', function () {
    $response = $this->get(PROXIED_URL);

    $response->assertOk();

    expect($response->getContent())
        ->toContain('http://app.example.test/build/assets/')
        ->not->toContain('https://app.example.test/build/assets/');
});
