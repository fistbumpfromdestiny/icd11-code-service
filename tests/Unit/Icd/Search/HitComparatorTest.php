<?php

use App\Icd\Search\HitComparator;
use App\Icd\Search\MatchedVia;
use App\Icd\Search\SearchHit;
use App\Models\IcdEntity;

function comparatorHit(string $code, MatchedVia $via = MatchedVia::Title, float $score = 0.5, bool $primary = true, bool $residual = false, int $titleLength = 20): SearchHit
{
    $entity = (new IcdEntity)->forceFill([
        'code' => $code,
        'title' => str_pad('Synthetic', $titleLength, 'x'),
        'is_primary_tabulation' => $primary,
        'is_residual' => $residual,
    ]);

    return new SearchHit($entity, $via, $score);
}

/** @param list<SearchHit> $hits */
function sortedCodes(array $hits): array
{
    usort($hits, HitComparator::compare(...));

    return array_map(fn (SearchHit $hit) => $hit->entity->code, $hits);
}

it('breaks equal scores by primary coding, specificity, title length, then code', function () {
    $hits = [
        comparatorHit('A', primary: false),
        comparatorHit('B', residual: true),
        comparatorHit('Q2', titleLength: 20),
        comparatorHit('Q1', titleLength: 20),
        comparatorHit('E', titleLength: 10),
    ];

    expect(sortedCodes($hits))->toBe(['E', 'Q1', 'Q2', 'B', 'A']);
});

it('puts a higher score first within a tier', function () {
    $hits = [
        comparatorHit('ZZ01', score: 0.2, titleLength: 10),
        comparatorHit('ZZ02', score: 0.9, primary: false, residual: true, titleLength: 40),
    ];

    expect(sortedCodes($hits))->toBe(['ZZ02', 'ZZ01']);
});

it('orders tiers code, title, partial, fuzzy regardless of score', function () {
    $hits = [
        comparatorHit('ZZ04', MatchedVia::Fuzzy, score: 0.99),
        comparatorHit('ZZ03', MatchedVia::Partial, score: 3),
        comparatorHit('ZZ02', MatchedVia::Title, score: 0.01),
        comparatorHit('ZZ01', MatchedVia::Code, score: 0),
    ];

    expect(sortedCodes($hits))->toBe(['ZZ01', 'ZZ02', 'ZZ03', 'ZZ04']);
});

it('orders the code tier exact match first, then by code, ignoring the other tie-breaks', function () {
    $hits = [
        comparatorHit('6A70.1', MatchedVia::Code, score: 0, titleLength: 10),
        comparatorHit('6A70.Z', MatchedVia::Code, score: 0, residual: true),
        comparatorHit('6A70', MatchedVia::Code, score: 1, titleLength: 40),
        comparatorHit('6A70.0', MatchedVia::Code, score: 0, primary: false),
    ];

    expect(sortedCodes($hits))->toBe(['6A70', '6A70.0', '6A70.1', '6A70.Z']);
});
