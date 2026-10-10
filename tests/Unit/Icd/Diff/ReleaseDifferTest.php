<?php

use App\Icd\Diff\Change;
use App\Icd\Diff\ChangeType;
use App\Icd\Diff\EntitySnapshot;
use App\Icd\Diff\ReleaseDiffer;
use App\Icd\Import\ChapterFilter;

it('reports nothing for identical releases', function () {
    $release = diffSnapshots(diffSnapshot('zz10', 'ZZ10', 'Synthetic condition'));

    expect((new ReleaseDiffer)->diff($release, $release)->changes)->toBe([]);
});

it('reports a URI only in the newer release as added', function () {
    $new = diffSnapshot('zz19', 'ZZ19', 'Synthetic added condition');

    $changes = (new ReleaseDiffer)->diff([], diffSnapshots($new))->changes;

    expect(diffSummary($changes))->toBe([['added', SYNTHETIC_URI.'zz19']])
        ->and($changes[0]->before)->toBeNull()
        ->and($changes[0]->after)->toBe($new->toArray());
});

it('reports a URI only in the older release as removed', function () {
    $old = diffSnapshot('zz11', 'ZZ11', 'Synthetic removed condition');

    $changes = (new ReleaseDiffer)->diff(diffSnapshots($old), [])->changes;

    expect(diffSummary($changes))->toBe([['removed', SYNTHETIC_URI.'zz11']])
        ->and($changes[0]->before)->toBe($old->toArray())
        ->and($changes[0]->after)->toBeNull();
});

it('reports an entity that left the configured chapters as removed', function () {
    $old = diffSnapshot('zz11', 'ZZ11', 'Synthetic condition', chapter: '21');
    $moved = diffSnapshot('zz11', 'ZZ11', 'Synthetic condition', chapter: '01');
    $inScope = iterator_to_array((new ChapterFilter(['06', '15', '21', '24']))->filter([$moved->toArray()]));

    $changes = (new ReleaseDiffer)->diff(diffSnapshots($old), diffSnapshots(...array_map(EntitySnapshot::fromArray(...), $inScope)))->changes;

    expect(diffSummary($changes))->toBe([['removed', SYNTHETIC_URI.'zz11']]);
});

it('reports a changed code as recoded with the old and new code', function () {
    $changes = (new ReleaseDiffer)->diff(
        diffSnapshots(diffSnapshot('zz12', 'ZZ12', 'Synthetic condition')),
        diffSnapshots(diffSnapshot('zz12', 'ZZ17', 'Synthetic condition')),
    )->changes;

    expect(diffSummary($changes))->toBe([['recoded', SYNTHETIC_URI.'zz12']])
        ->and($changes[0]->before['code'])->toBe('ZZ12')
        ->and($changes[0]->after['code'])->toBe('ZZ17');
});

it('reports a changed parent URI as moved', function () {
    $changes = (new ReleaseDiffer)->diff(
        diffSnapshots(diffSnapshot('zz13', 'ZZ13', 'Synthetic condition', parent: 'blockzz0')),
        diffSnapshots(diffSnapshot('zz13', 'ZZ13', 'Synthetic condition', parent: 'blockzz1')),
    )->changes;

    expect(diffSummary($changes))->toBe([['moved', SYNTHETIC_URI.'zz13']])
        ->and($changes[0]->before['parent_uri'])->toBe(SYNTHETIC_URI.'blockzz0')
        ->and($changes[0]->after['parent_uri'])->toBe(SYNTHETIC_URI.'blockzz1');
});

it('reports a code that moved to another URI as code_reused, naming the code and both URIs', function () {
    $changes = (new ReleaseDiffer)->diff(
        diffSnapshots(diffSnapshot('zz11', 'ZZ11', 'Synthetic removed condition')),
        diffSnapshots(diffSnapshot('zz19', 'ZZ11', 'Synthetic added condition')),
    )->changes;

    $reused = array_values(array_filter($changes, fn (Change $c) => $c->type === ChangeType::CodeReused));

    expect(diffSummary($changes))->toBe([
        ['added', SYNTHETIC_URI.'zz19'],
        ['removed', SYNTHETIC_URI.'zz11'],
        ['code_reused', SYNTHETIC_URI.'zz19'],
    ])
        ->and($reused[0]->before['code'])->toBe('ZZ11')
        ->and($reused[0]->before['linearization_uri'])->toBe(SYNTHETIC_URI.'zz11')
        ->and($reused[0]->after['code'])->toBe('ZZ11')
        ->and($reused[0]->after['linearization_uri'])->toBe(SYNTHETIC_URI.'zz19');
});

it('reports codes swapped between two URIs as two recodes and two code_reused records', function () {
    $changes = (new ReleaseDiffer)->diff(
        diffSnapshots(diffSnapshot('zz11', 'ZZ11', 'Synthetic one'), diffSnapshot('zz12', 'ZZ12', 'Synthetic two')),
        diffSnapshots(diffSnapshot('zz11', 'ZZ12', 'Synthetic one'), diffSnapshot('zz12', 'ZZ11', 'Synthetic two')),
    )->changes;

    expect(diffSummary($changes))->toBe([
        ['recoded', SYNTHETIC_URI.'zz11'],
        ['recoded', SYNTHETIC_URI.'zz12'],
        ['code_reused', SYNTHETIC_URI.'zz11'],
        ['code_reused', SYNTHETIC_URI.'zz12'],
    ]);
});

it('never reports blocks, which have no code, as recoded or code_reused', function () {
    $changes = (new ReleaseDiffer)->diff(
        diffSnapshots(diffSnapshot('blockzz0', null, 'Synthetic block', parent: 'ch21', blockId: 'BlockL1-ZZ0', kind: 'block')),
        diffSnapshots(diffSnapshot('blockzz9', null, 'Synthetic block', parent: 'ch21', blockId: 'BlockL1-ZZ9', kind: 'block')),
    )->changes;

    expect(diffSummary($changes))->toBe([
        ['added', SYNTHETIC_URI.'blockzz9'],
        ['removed', SYNTHETIC_URI.'blockzz0'],
    ]);
});

it('reports a block title change as retitled', function () {
    $changes = (new ReleaseDiffer)->diff(
        diffSnapshots(diffSnapshot('blockzz0', null, 'Synthetic conditions and findings', parent: 'ch21', blockId: 'BlockL1-ZZ0', kind: 'block')),
        diffSnapshots(diffSnapshot('blockzz0', null, 'Synthetic conditions or findings', parent: 'ch21', blockId: 'BlockL1-ZZ0', kind: 'block')),
    )->changes;

    expect(diffSummary($changes))->toBe([['retitled', SYNTHETIC_URI.'blockzz0']])
        ->and($changes[0]->before['block_id'])->toBe('BlockL1-ZZ0')
        ->and($changes[0]->after['title'])->toBe('Synthetic conditions or findings');
});

it('classifies a title change as retitled or retitled_cosmetic', function (string $before, string $after, string $type) {
    $changes = (new ReleaseDiffer)->diff(
        diffSnapshots(diffSnapshot('zz14', 'ZZ14', $before)),
        diffSnapshots(diffSnapshot('zz14', 'ZZ14', $after)),
    )->changes;

    expect(diffSummary($changes))->toBe([[$type, SYNTHETIC_URI.'zz14']])
        ->and($changes[0]->before['title'])->toBe($before)
        ->and($changes[0]->after['title'])->toBe($after);
})->with([
    'case only' => ['Ketamine', 'ketamine', 'retitled_cosmetic'],
    'hyphen to space' => ['Abnormal blood-pressure reading', 'Abnormal blood pressure reading', 'retitled_cosmetic'],
    'punctuation and extra whitespace' => ['Synthetic condition, unspecified', 'Synthetic condition  unspecified.', 'retitled_cosmetic'],
    'non-ASCII letters lowercased' => ['Synthetic Ödema', 'Synthetic ödema', 'retitled_cosmetic'],
    'and to or' => ['Fasciitis and fibromatosis', 'Fasciitis or fibromatosis', 'retitled'],
    'misspelling fixed' => ['Enteroccus resistant', 'Enterococcus resistant', 'retitled'],
    'words added' => ['Abnormal blood-pressure reading, without diagnosis', 'Abnormal blood-pressure reading, without diagnosis of hypertension', 'retitled'],
]);

it('gives one record per change type when one URI is recoded, retitled and moved', function () {
    $changes = (new ReleaseDiffer)->diff(
        diffSnapshots(diffSnapshot('zz12', 'ZZ12', 'Synthetic condition and more', parent: 'blockzz0')),
        diffSnapshots(diffSnapshot('zz12', 'ZZ17', 'Synthetic condition or more', parent: 'blockzz1')),
    )->changes;

    expect(diffSummary($changes))->toBe([
        ['recoded', SYNTHETIC_URI.'zz12'],
        ['moved', SYNTHETIC_URI.'zz12'],
        ['retitled', SYNTHETIC_URI.'zz12'],
    ]);
});

it('finds exactly one change of each type between diff-2025-01 and diff-2026-01', function () {
    $changeSet = (new ReleaseDiffer)->diff(icdSnapshots('diff-2025-01.txt'), icdSnapshots('diff-2026-01.txt'));

    expect(diffSummary($changeSet->changes))->toBe([
        ['added', SYNTHETIC_URI.'zz19'],
        ['removed', SYNTHETIC_URI.'zz11'],
        ['recoded', SYNTHETIC_URI.'zz12'],
        ['moved', SYNTHETIC_URI.'zz13'],
        ['code_reused', SYNTHETIC_URI.'zz19'],
        ['retitled', SYNTHETIC_URI.'zz14'],
        ['retitled_cosmetic', SYNTHETIC_URI.'zz15'],
    ])
        ->and($changeSet->countsByType())->toBe([
            'added' => 1, 'removed' => 1, 'recoded' => 1, 'moved' => 1,
            'code_reused' => 1, 'retitled' => 1, 'retitled_cosmetic' => 1,
        ]);
});

it('finds exactly one retitle between diff-2026-01 and diff-2027-01', function () {
    $changes = (new ReleaseDiffer)->diff(icdSnapshots('diff-2026-01.txt'), icdSnapshots('diff-2027-01.txt'))->changes;

    expect(diffSummary($changes))->toBe([['retitled', SYNTHETIC_URI.'zz10']]);
});
