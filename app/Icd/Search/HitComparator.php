<?php

namespace App\Icd\Search;

/**
 * Orders hits: by tier, then within the code tier exact match first and code
 * order; within the other tiers by score, then primary before supplementary,
 * specific before residual, shorter title, code, and block_id (blocks have
 * no code).
 */
final class HitComparator
{
    public static function compare(SearchHit $a, SearchHit $b): int
    {
        $ea = $a->entity;
        $eb = $b->entity;

        if ($a->matchedVia !== $b->matchedVia) {
            return $a->matchedVia->tier() <=> $b->matchedVia->tier();
        }

        if ($a->matchedVia === MatchedVia::Code) {
            return $b->score <=> $a->score
                ?: strcmp((string) $ea->code, (string) $eb->code);
        }

        return $b->score <=> $a->score
            ?: (int) ! $ea->is_primary_tabulation <=> (int) ! $eb->is_primary_tabulation
            ?: (int) $ea->is_residual <=> (int) $eb->is_residual
            ?: mb_strlen($ea->title) <=> mb_strlen($eb->title)
            ?: strcmp((string) $ea->code, (string) $eb->code)
            ?: strcmp((string) $ea->block_id, (string) $eb->block_id);
    }
}
