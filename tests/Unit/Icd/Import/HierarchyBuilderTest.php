<?php

use App\Icd\Import\ImportException;

it('derives parents from the title depth', function () {
    $parents = array_column(array_map(fn ($r) => [
        'key' => $r['code'] ?? $r['block_id'] ?? $r['title'],
        'parent' => $r['parent_uri'],
    ], parseIcdFixture('format-2026-01.txt')), 'parent', 'key');

    expect($parents)->toBe([
        'Synthetic symptoms chapter' => null,
        'BlockL1-ZZ0' => SYNTHETIC_URI.'ch21',
        'ZZ01' => SYNTHETIC_URI.'blockzz0',
        'ZZ01.0' => SYNTHETIC_URI.'zz01',
        'ZZ01.Z' => SYNTHETIC_URI.'zz01',
        'BlockL1-ZZ1' => SYNTHETIC_URI.'ch21',
        'ZZ02' => SYNTHETIC_URI.'blockzz1',
    ]);
});

it('assigns a category with DepthInKind 1 to the block above it', function () {
    $rows = array_column(array_filter(parseIcdFixture('format-2025-01.txt'), fn ($r) => $r['code'] !== null), null, 'code');

    expect($rows['ZZ02']['parent_uri'])->toBe(SYNTHETIC_URI.'blockzz1');
});

it('fails on Parent mismatches, listing each code in one message', function () {
    try {
        parseIcdFixture('parent-mismatch.txt');
        $this->fail('Expected an ImportException');
    } catch (ImportException $e) {
        expect($e->getMessage())->toContain('ZZ01.0')->toContain('ZZ02')->not->toContain('ZZ01.Z');
    }
});

it('skips the Parent check when the schema has no Parent column', function () {
    expect(parseIcdFixture('format-2025-01.txt'))->toHaveCount(7);
});

it('fails on a Grouping mismatch, listing the code', function () {
    try {
        parseIcdFixture('grouping-mismatch.txt');
        $this->fail('Expected an ImportException');
    } catch (ImportException $e) {
        expect($e->getMessage())->toContain('ZZ01')->toContain('BlockL1-ZZ1')->not->toContain('ZZ02');
    }
});

it('fails on a depth jump of more than one level, naming the code', function () {
    parseIcdFixture('depth-jump.txt');
})->throws(ImportException::class, 'ZZ01.0');
