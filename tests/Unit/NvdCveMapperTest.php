<?php

use App\Services\NvdCveMapper;

beforeEach(function () {
    $this->mapper = new NvdCveMapper;
});

/**
 * @param  array<string, mixed>  $metrics
 * @return array<string, mixed>
 */
function cveWithMetrics(array $metrics): array
{
    return [
        'id' => 'CVE-2026-0001',
        'published' => '2026-01-01T00:00:00.000',
        'lastModified' => '2026-02-01T00:00:00.000',
        'descriptions' => [
            ['lang' => 'es', 'value' => 'Una vulnerabilidad'],
            ['lang' => 'en', 'value' => 'A vulnerability'],
        ],
        'metrics' => $metrics,
    ];
}

/**
 * @return array<string, mixed>
 */
function metric(string $type, float $score, string $vector, string $severity): array
{
    return [
        'type' => $type,
        'cvssData' => [
            'baseScore' => $score,
            'vectorString' => $vector,
            'version' => '3.1',
            'baseSeverity' => $severity,
        ],
    ];
}

test('it reads the english description', function () {
    expect($this->mapper->attributes(cveWithMetrics([]))['description'])->toBe('A vulnerability');
});

test('it prefers the primary metric wherever it sits in the family', function (int $primaryIndex) {
    $entries = [metric('Secondary', 5.5, 'CVSS:3.1/CNA', 'MEDIUM')];
    array_splice($entries, $primaryIndex, 0, [metric('Primary', 9.8, 'CVSS:3.1/NVD', 'CRITICAL')]);

    $attributes = $this->mapper->attributes(cveWithMetrics(['cvssMetricV31' => $entries]));

    expect($attributes['cvss_score'])->toBe(9.8)
        ->and($attributes['cvss_vector'])->toBe('CVSS:3.1/NVD')
        ->and($attributes['cvss_severity'])->toBe('CRITICAL');
})->with(['listed first' => 0, 'listed second' => 1]);

test('every cvss column comes from the same entry', function () {
    $attributes = $this->mapper->attributes(cveWithMetrics(['cvssMetricV31' => [
        metric('Secondary', 5.4, 'CVSS:3.1/CNA', 'MEDIUM'),
        metric('Primary', 5.4, 'CVSS:3.1/NVD', 'MEDIUM'),
    ]]));

    expect($attributes['cvss_vector'])->toBe('CVSS:3.1/NVD');
});

test('it falls back to the first entry when nvd published no primary', function () {
    $attributes = $this->mapper->attributes(cveWithMetrics(['cvssMetricV31' => [
        metric('Secondary', 5.5, 'CVSS:3.1/CNA', 'MEDIUM'),
    ]]));

    expect($attributes['cvss_score'])->toBe(5.5)
        ->and($attributes['cvss_vector'])->toBe('CVSS:3.1/CNA');
});

test('it reads the newest metric family present', function () {
    $attributes = $this->mapper->attributes(cveWithMetrics([
        'cvssMetricV30' => [metric('Primary', 7.5, 'CVSS:3.0/OLD', 'HIGH')],
        'cvssMetricV31' => [metric('Primary', 9.8, 'CVSS:3.1/NEW', 'CRITICAL')],
    ]));

    expect($attributes['cvss_score'])->toBe(9.8);
});

test('it ignores cvss 4.0 while a 3.1 score is present', function () {
    $attributes = $this->mapper->attributes(cveWithMetrics([
        'cvssMetricV40' => [metric('Primary', 6.9, 'CVSS:4.0/X', 'MEDIUM')],
        'cvssMetricV31' => [metric('Primary', 9.8, 'CVSS:3.1/NEW', 'CRITICAL')],
    ]));

    expect($attributes['cvss_score'])->toBe(9.8);
});

test('it reads a cvss 2 severity from the entry itself', function () {
    $attributes = $this->mapper->attributes(cveWithMetrics(['cvssMetricV2' => [[
        'type' => 'Primary',
        'baseSeverity' => 'HIGH',
        'cvssData' => ['baseScore' => 7.5, 'vectorString' => 'AV:N/AC:L/Au:N/C:P/I:P/A:P', 'version' => '2.0'],
    ]]]));

    expect($attributes['cvss_severity'])->toBe('HIGH')
        ->and($attributes['cvss_score'])->toBe(7.5);
});

test('an unscored cve maps to null rather than a guess', function (array $metrics) {
    $attributes = $this->mapper->attributes(cveWithMetrics($metrics));

    expect($attributes['cvss_score'])->toBeNull()
        ->and($attributes['cvss_vector'])->toBeNull()
        ->and($attributes['cvss_severity'])->toBeNull();
})->with([
    'no metrics at all' => [[]],
    'an empty family' => [['cvssMetricV31' => []]],
    'only families that carry no score' => [['ssvcV203' => [['id' => 'CVE-2026-0001']]]],
]);
