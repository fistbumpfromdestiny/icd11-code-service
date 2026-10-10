<?php

namespace App\Icd\Search;

use App\Models\IcdEntity;

/**
 * One search result. The score's meaning depends on the tier: 1 for an exact
 * code and 0 for a prefix, the number of words in the own title for title,
 * the number of matching words for partial, word similarity for fuzzy.
 */
final readonly class SearchHit
{
    public function __construct(
        public IcdEntity $entity,
        public MatchedVia $matchedVia,
        public float $score,
    ) {}
}
