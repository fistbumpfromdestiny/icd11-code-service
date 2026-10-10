<?php

use App\Icd\Diff\ReleaseDiffer;

it('counts changes per change type and chapter, taking a removed entity\'s chapter from before', function () {
    $changes = (new ReleaseDiffer)->diff(
        diffSnapshots(
            diffSnapshot('zz11', 'ZZ11', 'Synthetic removed condition', chapter: '15'),
            diffSnapshot('zz14', 'ZZ14', 'Synthetic old title'),
        ),
        diffSnapshots(
            diffSnapshot('zz19', 'ZZ19', 'Synthetic added condition', chapter: '06'),
            diffSnapshot('zz14', 'ZZ14', 'Synthetic new title'),
        ),
    );

    expect($changes->countsByTypeAndChapter())->toBe([
        'added' => ['06' => 1],
        'removed' => ['15' => 1],
        'recoded' => [],
        'moved' => [],
        'code_reused' => [],
        'retitled' => ['21' => 1],
        'retitled_cosmetic' => [],
    ]);
});
