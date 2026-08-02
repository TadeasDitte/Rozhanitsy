<?php

use App\Services\VersionComparator;

beforeEach(function () {
    $this->comparator = new VersionComparator;
});

test('an open ended range affects every version', function () {
    expect($this->comparator->isAffected('1.0.0', null, true, null, false))->toBeTrue();
});

test('an exclusive upper bound excludes the boundary version', function () {
    expect($this->comparator->isAffected('5.8.0', null, true, '5.8.0', false))->toBeFalse()
        ->and($this->comparator->isAffected('5.7.9', null, true, '5.8.0', false))->toBeTrue();
});

test('an inclusive upper bound includes the boundary version', function () {
    expect($this->comparator->isAffected('5.8.0', null, true, '5.8.0', true))->toBeTrue()
        ->and($this->comparator->isAffected('5.8.1', null, true, '5.8.0', true))->toBeFalse();
});

test('an inclusive lower bound includes the boundary version', function () {
    expect($this->comparator->isAffected('2.0.0', '2.0.0', true, null, false))->toBeTrue()
        ->and($this->comparator->isAffected('1.9.9', '2.0.0', true, null, false))->toBeFalse();
});

test('an exclusive lower bound excludes the boundary version', function () {
    expect($this->comparator->isAffected('2.0.0', '2.0.0', false, null, false))->toBeFalse()
        ->and($this->comparator->isAffected('2.0.1', '2.0.0', false, null, false))->toBeTrue();
});

test('a closed range only matches versions inside it', function (string $version, bool $expected) {
    expect($this->comparator->isAffected($version, '1.5.0', true, '2.0.0', false))->toBe($expected);
})->with([
    ['1.4.9', false],
    ['1.5.0', true],
    ['1.7.3', true],
    ['1.9.9', true],
    ['2.0.0', false],
    ['2.1.0', false],
]);

test('a leading v is ignored on both sides', function () {
    expect($this->comparator->isAffected('v1.5.0', 'v1.0.0', true, 'v2.0.0', false))->toBeTrue();
});

test('build metadata is stripped before comparison', function () {
    expect($this->comparator->isAffected('1.2.3+build99', null, true, '1.2.3', true))->toBeTrue()
        ->and($this->comparator->isAffected('1.2.3+build99', '1.2.3', true, null, false))->toBeTrue();
});

test('a prerelease sorts below its release version', function () {
    expect($this->comparator->isAffected('1.2.0-beta1', null, true, '1.2.0', false))->toBeTrue()
        ->and($this->comparator->isAffected('1.2.0', null, true, '1.2.0', false))->toBeFalse();
});

test('a prerelease with build metadata is handled', function () {
    expect($this->comparator->isAffected('v1.2.0-beta1+build99', null, true, '1.2.0', false))->toBeTrue();
});

test('versions of differing depth compare correctly', function () {
    expect($this->comparator->isAffected('1.2', null, true, '1.2.1', false))->toBeTrue()
        ->and($this->comparator->isAffected('1.2.1', null, true, '1.2', false))->toBeFalse();
});

test('an unparseable installed version is not reported as affected', function () {
    expect($this->comparator->isAffected('', null, true, null, false))->toBeFalse();
});

test('a two segment version is comparable with three segment bounds', function () {
    expect($this->comparator->isAffected('1.0', '1.0.0', true, '2.0.0', false))->toBeTrue()
        ->and($this->comparator->isAffected('2.0', '1.0.0', true, '2.0.0', false))->toBeFalse();
});

test('a one segment version is padded', function () {
    expect($this->comparator->isAffected('2', '1.0.0', true, '2.0.0', false))->toBeFalse()
        ->and($this->comparator->isAffected('1', '1.0.0', true, '2.0.0', false))->toBeTrue();
});

test('padding does not disturb prerelease ordering', function () {
    expect($this->comparator->isAffected('1.2-beta1', null, true, '1.2.0', false))->toBeTrue()
        ->and($this->comparator->isAffected('1.2', null, true, '1.2.0', false))->toBeFalse();
});

test('an exact point range matches only that version', function () {
    expect($this->comparator->isAffected('2.14.1', '2.14.1', true, '2.14.1', true))->toBeTrue()
        ->and($this->comparator->isAffected('2.15.0', '2.14.1', true, '2.14.1', true))->toBeFalse()
        ->and($this->comparator->isAffected('2.14.0', '2.14.1', true, '2.14.1', true))->toBeFalse();
});
