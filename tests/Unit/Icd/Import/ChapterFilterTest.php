<?php

use App\Icd\Import\ChapterFilter;

it('keeps only the configured chapters', function () {
    $rows = [['chapter' => '01'], ['chapter' => '06'], ['chapter' => '6'], ['chapter' => '21'], ['chapter' => 'X']];

    $kept = iterator_to_array((new ChapterFilter(['06', '15', '21', '24']))->filter($rows), false);

    expect($kept)->toBe([['chapter' => '06'], ['chapter' => '21']]);
});

it('drops chapters outside scope from a release file', function () {
    $chapters = array_unique(array_column(parseIcdFixture('format-2026-01.txt'), 'chapter'));

    expect(array_values($chapters))->toBe(['21']);
});
