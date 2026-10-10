<?php

use App\Icd\Import\ImportException;
use App\Icd\Import\SchemaDetector;

it('detects the 2025-01 format', function () {
    $schema = detectIcdFixture('format-2025-01.txt');

    expect($schema->fieldCount)->toBe(17)
        ->and($schema->version)->toBe('2025-01')
        ->and($schema->versionStamp)->toBe('Version:2025 Jan 24 - 22:30 UTC')
        ->and($schema->has('coding_note'))->toBeFalse()
        ->and($schema->has('parent'))->toBeFalse();
});

it('detects the 2026-01 format', function () {
    $schema = detectIcdFixture('format-2026-01.txt');

    expect($schema->fieldCount)->toBe(19)
        ->and($schema->version)->toBe('2026-01')
        ->and($schema->has('coding_note'))->toBeTrue()
        ->and($schema->has('parent'))->toBeTrue()
        ->and($schema->columnMap()['ignored'])->toBe(['DepthInKind', 'BrowserLink'])
        ->and($schema->columnMap()['unknown'])->toBe([]);
});

it('maps header aliases to the same columns, in any order', function () {
    $map = detectIcdFixture('header-aliases.txt')->columnMap();

    expect($map['columns'])->toMatchArray([
        'linearization_uri' => 'Linearization (release) URI',
        'is_leaf' => 'IsLeaf',
        'is_primary_tabulation' => 'Primary Tabulation',
    ])->and(array_keys($map['columns']))
        ->toEqualCanonicalizing(array_keys(detectIcdFixture('format-2026-01.txt')->columnMap()['columns']));
});

it('ignores an unknown extra column and records it in the column map', function () {
    $schema = detectIcdFixture('unknown-column.txt');

    expect($schema->fieldCount)->toBe(20)
        ->and($schema->columnMap()['unknown'])->toBe(['SyntheticNewColumn']);
});

it('fails on a missing required column, naming it', function () {
    detectIcdFixture('missing-chapterno.txt');
})->throws(ImportException::class, 'ChapterNo');

it('fails when the Version stamp is missing', function () {
    detectIcdFixture('missing-version.txt');
})->throws(ImportException::class, 'no Version: stamp');

it('fails on an unparseable Version stamp, quoting the cell', function () {
    detectIcdFixture('bad-version.txt');
})->throws(ImportException::class, 'Version:sometime');

it('fails when a column appears twice under an alias', function () {
    (new SchemaDetector)->detect([
        'Linearization URI', 'Linearization (release) URI', 'Foundation URI', 'Code', 'BlockId',
        'Title', 'ClassKind', 'IsResidual', 'ChapterNo', 'isLeaf', 'Primary tabulation',
        'Grouping1', 'Grouping2', 'Grouping3', 'Grouping4', 'Grouping5', 'Version:2026 Jan 17 - 05:30 UTC',
    ]);
})->throws(ImportException::class, 'Linearization (release) URI');
