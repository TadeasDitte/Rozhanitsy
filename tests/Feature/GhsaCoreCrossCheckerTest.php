<?php

use App\Services\GhsaCoreCrossChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->checker = new GhsaCoreCrossChecker;
});

test('it detects an ecosystem package tagged on the ghsa advisory', function () {
    Http::fake([
        'api.github.com/*' => Http::response([
            [
                'ghsa_id' => 'GHSA-44v2-prcf-pc3m',
                'vulnerabilities' => [
                    ['package' => ['ecosystem' => 'composer', 'name' => 'joomla/database']],
                ],
            ],
        ]),
    ]);

    expect($this->checker->hasEcosystemPackage('CVE-2025-25226'))->toBeTrue();
});

test('it returns false when ghsa has a record with no ecosystem package', function () {
    Http::fake([
        'api.github.com/*' => Http::response([
            ['ghsa_id' => 'GHSA-xxxx', 'vulnerabilities' => []],
        ]),
    ]);

    expect($this->checker->hasEcosystemPackage('CVE-2026-40383'))->toBeFalse();
});

test('it returns false when ghsa has no record for the cve at all', function () {
    Http::fake([
        'api.github.com/*' => Http::response(status: 404),
    ]);

    expect($this->checker->hasEcosystemPackage('CVE-2026-40383'))->toBeFalse();
});

test('it returns null instead of a false negative when ghsa is rate limiting or erroring', function () {
    Http::fake([
        'api.github.com/*' => Http::response(status: 403),
    ]);

    expect($this->checker->hasEcosystemPackage('CVE-2026-40383'))->toBeNull();
});

test('it returns null on a connection failure rather than assuming a clean bill of health', function () {
    Http::fake([
        'api.github.com/*' => fn () => throw new ConnectionException('timed out'),
    ]);

    expect($this->checker->hasEcosystemPackage('CVE-2026-40383'))->toBeNull();
});

test('it sends the cve id as a query parameter', function () {
    Http::fake([
        'api.github.com/*' => Http::response([]),
    ]);

    $this->checker->hasEcosystemPackage('CVE-2025-25226');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.github.com/advisories?cve_id=CVE-2025-25226');
});
