<?php

it('parses both release formats to the same entity shape', function () {
    $old = parseIcdFixture('format-2025-01.txt');
    $new = parseIcdFixture('format-2026-01.txt');
    $withoutSourceOnlyFields = fn (array $rows) => array_map(
        fn ($row) => array_diff_key($row, array_flip(['coding_note', 'parent_foundation_uri', 'line'])),
        $rows,
    );

    expect($old)->toHaveCount(7)
        ->and(array_map('array_keys', $old))->toBe(array_map('array_keys', $new))
        ->and($withoutSourceOnlyFields($old))->toBe($withoutSourceOnlyFields($new))
        ->and(array_unique(array_column($old, 'coding_note')))->toBe([null])
        ->and(array_filter(array_column($new, 'coding_note')))->toHaveCount(3);
});

it('parses aliased and reordered headers the same as the canonical header', function () {
    expect(parseIcdFixture('header-aliases.txt'))->toBe(parseIcdFixture('format-2026-01.txt'));
});

it('parses a file with an unknown extra column the same as without it', function () {
    expect(parseIcdFixture('unknown-column.txt'))->toBe(parseIcdFixture('format-2026-01.txt'));
});
