<?php

use App\Services\NvdPageReader;
use GuzzleHttp\Psr7\Utils;

/**
 * @param  array<string, mixed>|string  $payload
 */
function reader(array|string $payload): NvdPageReader
{
    return new NvdPageReader(Utils::streamFor(
        is_string($payload) ? $payload : json_encode($payload)
    ));
}

/**
 * @param  array<string, mixed>|string  $payload
 * @return array<int, mixed>
 */
function entries(array|string $payload): array
{
    return iterator_to_array(reader($payload)->vulnerabilities(), false);
}

test('it yields every entry in the vulnerabilities array', function () {
    $entries = entries([
        'totalResults' => 3,
        'vulnerabilities' => [
            ['cve' => ['id' => 'CVE-2026-0001']],
            ['cve' => ['id' => 'CVE-2026-0002']],
            ['cve' => ['id' => 'CVE-2026-0003']],
        ],
    ]);

    expect($entries)->toHaveCount(3)
        ->and(array_column(array_column($entries, 'cve'), 'id'))
        ->toBe(['CVE-2026-0001', 'CVE-2026-0002', 'CVE-2026-0003']);
});

test('it reads totalResults from the payload', function () {
    $reader = reader(['totalResults' => 372505, 'vulnerabilities' => []]);

    iterator_to_array($reader->vulnerabilities(), false);

    expect($reader->totalResults())->toBe(372505);
});

/**
 * The scanner walks keys in whatever order it meets them, so a payload that
 * puts the count after the array must still report it.
 */
test('it reads totalResults declared after the vulnerabilities array', function () {
    $reader = reader([
        'vulnerabilities' => [['cve' => ['id' => 'CVE-2026-0001']]],
        'totalResults' => 42,
    ]);

    $entries = iterator_to_array($reader->vulnerabilities(), false);

    expect($entries)->toHaveCount(1)
        ->and($reader->totalResults())->toBe(42);
});

test('totalResults is zero when the payload omits it', function () {
    $reader = reader(['vulnerabilities' => []]);

    iterator_to_array($reader->vulnerabilities(), false);

    expect($reader->totalResults())->toBe(0);
});

test('an empty vulnerabilities array yields nothing', function () {
    expect(entries(['totalResults' => 0, 'vulnerabilities' => []]))->toBe([]);
});

/**
 * Braces and brackets inside description text must not move the depth counter
 * that decides where one CVE ends and the next begins.
 */
test('it does not let punctuation inside strings end an entry early', function () {
    $description = 'Fixed in {"version": [1,2]} — see }}}] and the array [{';

    $entries = entries([
        'vulnerabilities' => [
            ['cve' => ['id' => 'CVE-2026-0001', 'description' => $description]],
            ['cve' => ['id' => 'CVE-2026-0002']],
        ],
    ]);

    expect($entries)->toHaveCount(2)
        ->and($entries[0]['cve']['description'])->toBe($description)
        ->and($entries[1]['cve']['id'])->toBe('CVE-2026-0002');
});

test('it handles escaped quotes and backslashes inside strings', function () {
    $description = 'A \\ backslash, a "quote", a trailing pair \\\\ and \\" together';

    $entries = entries([
        'vulnerabilities' => [
            ['cve' => ['id' => 'CVE-2026-0001', 'description' => $description]],
            ['cve' => ['id' => 'CVE-2026-0002']],
        ],
    ]);

    expect($entries)->toHaveCount(2)
        ->and($entries[0]['cve']['description'])->toBe($description);
});

test('it preserves unicode and escape sequences', function () {
    $description = "Tab\there, newline\nhere, ünïcode and an emoji 🔒";

    $entries = entries([
        'vulnerabilities' => [['cve' => ['id' => 'CVE-2026-0001', 'description' => $description]]],
    ]);

    expect($entries[0]['cve']['description'])->toBe($description);
});

test('it reads a pretty printed payload', function () {
    $payload = json_encode([
        'totalResults' => 2,
        'vulnerabilities' => [
            ['cve' => ['id' => 'CVE-2026-0001', 'metrics' => ['cvssMetricV31' => [['cvssData' => ['baseScore' => 9.8]]]]]],
            ['cve' => ['id' => 'CVE-2026-0002']],
        ],
    ], JSON_PRETTY_PRINT);

    $reader = reader($payload);
    $entries = iterator_to_array($reader->vulnerabilities(), false);

    expect($entries)->toHaveCount(2)
        ->and($entries[0]['cve']['metrics']['cvssMetricV31'][0]['cvssData']['baseScore'])->toBe(9.8)
        ->and($reader->totalResults())->toBe(2);
});

test('it carries deeply nested configurations through intact', function () {
    $entry = [
        'cve' => [
            'id' => 'CVE-2026-0001',
            'configurations' => [
                [
                    'operator' => 'AND',
                    'nodes' => [
                        ['cpeMatch' => [
                            ['vulnerable' => true, 'criteria' => 'cpe:2.3:a:acme:widget:*:*:*:*:*:*:*:*', 'versionEndExcluding' => '2.0.0'],
                        ]],
                        ['cpeMatch' => [
                            ['vulnerable' => false, 'criteria' => 'cpe:2.3:o:acme:os:-:*:*:*:*:*:*:*'],
                        ]],
                    ],
                ],
            ],
        ],
    ];

    expect(entries(['vulnerabilities' => [$entry]])[0])->toBe($entry);
});

test('it handles null and boolean scalars beside the array', function () {
    $reader = reader('{"format":"NVD_CVE","empty":null,"flag":true,"totalResults":1,"vulnerabilities":[{"cve":{"id":"CVE-2026-0001"}}]}');

    expect(iterator_to_array($reader->vulnerabilities(), false))->toHaveCount(1)
        ->and($reader->totalResults())->toBe(1);
});

test('it fails on a payload truncated mid entry', function () {
    entries('{"totalResults":2,"vulnerabilities":[{"cve":{"id":"CVE-2026-0001"');
})->throws(RuntimeException::class);

test('it fails on a payload truncated before the array closes', function () {
    entries('{"totalResults":2,"vulnerabilities":[{"cve":{"id":"CVE-2026-0001"}}');
})->throws(RuntimeException::class);

test('it fails on an entry that is balanced but not valid json', function () {
    entries('{"vulnerabilities":[{"cve":{"id":01}}]}');
})->throws(JsonException::class);

test('it fails when the payload is not an object', function () {
    entries('[{"cve":{"id":"CVE-2026-0001"}}]');
})->throws(RuntimeException::class);

/**
 * The reason this class exists. A page is decoded one entry at a time, so peak
 * memory has to track the largest entry rather than the page — otherwise
 * `sources.page_size` goes back to being a memory setting.
 */
test('memory stays flat across a full sized page', function () {
    $entry = ['cve' => [
        'id' => 'CVE-2026-0001',
        'description' => str_repeat('a padded description. ', 200),
        'configurations' => [['nodes' => [['cpeMatch' => array_fill(0, 40, [
            'vulnerable' => true,
            'criteria' => 'cpe:2.3:a:acme:widget:1.0:*:*:*:*:*:*:*',
        ])]]]],
    ]];

    $page = json_encode(['totalResults' => 2000, 'vulnerabilities' => array_fill(0, 2000, $entry)]);

    expect(strlen($page))->toBeGreaterThan(8 * 1024 * 1024);

    $stream = Utils::streamFor(fopen('php://temp', 'r+'));
    $stream->write($page);
    unset($page);

    $baseline = memory_get_usage();
    $count = 0;

    foreach ((new NvdPageReader($stream))->vulnerabilities() as $ignored) {
        $count++;
    }

    expect($count)->toBe(2000)
        ->and(memory_get_usage() - $baseline)->toBeLessThan(2 * 1024 * 1024);
});
