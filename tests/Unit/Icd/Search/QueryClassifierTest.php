<?php

use App\Icd\Search\QueryClassifier;

it('treats codes and code prefixes as code queries, upper-cased', function (string $query, string $code) {
    $classified = (new QueryClassifier)->classify($query);

    expect($classified->isCode)->toBeTrue()
        ->and($classified->code)->toBe($code);
})->with([
    ['6A70', '6A70'],
    ['6A7', '6A7'],
    ['6a7', '6A7'],
    ['6A', '6A'],
    ['  me84.2 ', 'ME84.2'],
    ['ME84.20', 'ME84.20'],
    ['6d80.z', '6D80.Z'],
    ['FA3Z', 'FA3Z'],
]);

it('treats words as free text', function (string $query) {
    expect((new QueryClassifier)->classify($query)->isCode)->toBeFalse();
})->with(['back', 'pain', 'ms', 'ME', 'depr', 'back pain', 'b12']);

it('normalizes free text into lower-case words', function (string $query, array $words) {
    $classified = (new QueryClassifier)->classify($query);

    expect($classified->code)->toBeNull()
        ->and($classified->words)->toBe($words);
})->with([
    ['  Back   Pain ', ['back', 'pain']],
    ["Alzheimer's onset", ['alzheimers', 'onset']],
    ['non-specific back pain', ['non', 'specific', 'back', 'pain']],
    ['back & (pain !', ['back', 'pain']],
    ["'depr':* | !x <-> y", ['depr', 'x', 'y']],
]);

it('gives no words for a query of only operator characters', function () {
    expect((new QueryClassifier)->classify('&| !:*')->words)->toBe([]);
});
