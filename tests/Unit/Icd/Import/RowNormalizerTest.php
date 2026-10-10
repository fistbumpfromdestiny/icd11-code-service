<?php

use App\Icd\Import\ImportException;
use App\Icd\Import\RowNormalizer;

it('normalizes a category', function () {
    $row = normalizeIcdFixture('format-2026-01.txt')[SYNTHETIC_URI.'zz01.0'];

    expect($row)->toBe([
        'line' => 7,
        'linearization_uri' => SYNTHETIC_URI.'zz01.0',
        'foundation_uri' => 'http://id.who.int/icd/entity/synthetic/zz01.0',
        'code' => 'ZZ01.0',
        'block_id' => null,
        'title' => 'Synthetic back pain, upper',
        'depth' => 3,
        'class_kind' => 'category',
        'chapter' => '21',
        'is_leaf' => true,
        'is_residual' => false,
        'is_primary_tabulation' => true,
        'groupings' => ['BlockL1-ZZ0'],
        'coding_note' => "Synthetic first paragraph of a coding note.\n\nSynthetic second paragraph of a coding note.",
        'parent_foundation_uri' => 'http://id.who.int/icd/entity/synthetic/zz01',
    ]);
});

it('normalizes a chapter and a block', function () {
    $rows = normalizeIcdFixture('format-2026-01.txt');

    expect($rows[SYNTHETIC_URI.'ch21'])->toMatchArray([
        'code' => null, 'block_id' => null, 'title' => 'Synthetic symptoms chapter', 'depth' => 0,
        'is_primary_tabulation' => null, 'groupings' => [], 'coding_note' => null, 'parent_foundation_uri' => null,
    ])->and($rows[SYNTHETIC_URI.'blockzz0'])->toMatchArray([
        'code' => null, 'block_id' => 'BlockL1-ZZ0', 'title' => 'Synthetic back pain conditions', 'depth' => 1,
        'class_kind' => 'block', 'is_leaf' => false, 'is_primary_tabulation' => null,
    ]);
});

it('keeps a residual without foundation URI', function () {
    $row = normalizeIcdFixture('format-2026-01.txt')[SYNTHETIC_URI.'zz01/unspecified'];

    expect($row)->toMatchArray([
        'foundation_uri' => null, 'code' => 'ZZ01.Z', 'is_residual' => true, 'is_primary_tabulation' => false,
    ]);
});

it('gives null for the optional columns of the 2025-01 format', function () {
    $row = normalizeIcdFixture('format-2025-01.txt')[SYNTHETIC_URI.'zz01.0'];

    expect($row['coding_note'])->toBeNull()
        ->and($row['parent_foundation_uri'])->toBeNull()
        ->and($row['line'])->toBe(7);
});

it('strips surrounding whitespace from titles and codes only', function () {
    $schema = detectIcdFixture('format-2026-01.txt');
    $fields = icdFields($schema, [
        'linearization_uri' => SYNTHETIC_URI.'x', 'code' => ' ZZ09 ', 'title' => ' - - Synthetic  spaced title ',
        'class_kind' => 'category', 'is_residual' => 'False', 'chapter' => '21', 'is_leaf' => 'True',
        'coding_note' => ' Synthetic note ',
    ]);

    $row = (new RowNormalizer)->normalize($fields, $schema, 2);

    expect($row['code'])->toBe('ZZ09')
        ->and($row['title'])->toBe('Synthetic  spaced title')
        ->and($row['depth'])->toBe(2)
        ->and($row['coding_note'])->toBe(' Synthetic note ');
});

it('fails on a boolean that is not True or False, naming the line', function () {
    $schema = detectIcdFixture('format-2026-01.txt');
    $fields = icdFields($schema, [
        'linearization_uri' => SYNTHETIC_URI.'x', 'title' => 'Synthetic chapter', 'class_kind' => 'chapter',
        'is_residual' => 'yes', 'chapter' => '21', 'is_leaf' => 'False',
    ]);

    (new RowNormalizer)->normalize($fields, $schema, 42);
})->throws(ImportException::class, 'IsResidual on line 42');

it('fails on an empty identity or chapter, naming the column and line', function (string $column, string $header) {
    $schema = detectIcdFixture('format-2026-01.txt');
    $values = [
        'linearization_uri' => SYNTHETIC_URI.'x', 'title' => 'Synthetic chapter', 'class_kind' => 'chapter',
        'is_residual' => 'False', 'chapter' => '21', 'is_leaf' => 'False',
    ];
    $values[$column] = ' ';

    expect(fn () => (new RowNormalizer)->normalize(icdFields($schema, $values), $schema, 42))
        ->toThrow(ImportException::class, "{$header} on line 42 is empty");
})->with([
    ['linearization_uri', 'Linearization URI'],
    ['chapter', 'ChapterNo'],
]);
