<?php

namespace App\Icd\Search;

/**
 * How a hit matched, which is also its tier: code > title (all words) >
 * partial (some words) > fuzzy.
 */
enum MatchedVia: string
{
    case Code = 'code';
    case Title = 'title';
    case Partial = 'partial';
    case Fuzzy = 'fuzzy';

    /** Lower is better. */
    public function tier(): int
    {
        return match ($this) {
            self::Code => 0,
            self::Title => 1,
            self::Partial => 2,
            self::Fuzzy => 3,
        };
    }
}
