<?php

namespace App\Icd\Search;

use App\Models\IcdEntity;
use App\Models\Release;
use Illuminate\Support\Facades\DB;

/**
 * Searches the categories of one release in tiers: code, title (all words),
 * partial (some words), fuzzy. Each entity is returned once, with its best
 * tier. Each tier fetches all its matches, so ties are decided by
 * HitComparator rather than by SQL.
 *
 * User input reaches SQL only as bound parameters, and free-text words
 * contain only letters and digits (see QueryClassifier).
 */
class CodeSearch
{
    public function __construct(private QueryClassifier $classifier) {}

    /** @return list<SearchHit> */
    public function search(string $query, Release $release, int $limit = 10): array
    {
        $classified = $this->classifier->classify($query);

        if ($classified->isCode) {
            $tiers = [fn () => $this->byCode($classified->code, $release)];
        } elseif ($classified->words === []) {
            return [];
        } else {
            $words = $classified->words;
            $tiers = [
                fn () => $this->byAllWords($words, $release),
                fn () => count($words) > 1 ? $this->bySomeWords($words, $release) : [],
                fn () => $this->byTrigram(implode(' ', $words), $release),
            ];
        }

        /** @var array<int, SearchHit> $hits keyed by entity id; the first (best) tier wins */
        $hits = [];
        foreach ($tiers as $tier) {
            if (count($hits) >= $limit) {
                break;
            }
            foreach ($tier() as $hit) {
                $hits[$hit->entity->id] ??= $hit;
            }
        }

        $hits = array_values($hits);
        usort($hits, HitComparator::compare(...));

        return array_slice($hits, 0, $limit);
    }

    /** @return list<SearchHit> exact code scores 1, a longer code with the prefix 0 */
    private function byCode(string $code, Release $release): array
    {
        return $this->hits(MatchedVia::Code,
            "select e.*, (e.code = ?)::int as score
             from icd_entities as e
             where e.release_id = ? and e.class_kind = 'category' and e.code like ?",
            [$code, $release->id, $code.'%'],
        );
    }

    /**
     * Every word must match the own or an ancestor title. The score is the
     * number of words found in the own title (weight A), so hits that match
     * the same way tie and HitComparator decides.
     *
     * @param  list<string>  $words
     */
    private function byAllWords(array $words, Release $release): array
    {
        [$sql, $bindings] = $this->terms($words);
        $query = '('.implode(' && ', $sql).')';
        $terms = 'array['.implode(', ', $sql).']';

        return $this->hits(MatchedVia::Title,
            "select e.*, (select count(*) from unnest(q.terms) as u(term) where ts_filter(e.search_vector, '{a}') @@ u.term) as score
             from icd_entities as e, (select {$query} as query, {$terms} as terms) as q
             where e.release_id = ? and e.class_kind = 'category' and e.search_vector @@ q.query",
            [...$bindings, ...$bindings, $release->id],
        );
    }

    /** @param list<string> $words score is the number of words that match */
    private function bySomeWords(array $words, Release $release): array
    {
        [$sql, $bindings] = $this->terms($words);
        $terms = 'array['.implode(', ', $sql).']';

        return $this->hits(MatchedVia::Partial,
            "select * from (
                 select e.*, (select count(*) from unnest(t.terms) as u(term) where e.search_vector @@ u.term) as score
                 from icd_entities as e, (select {$terms} as terms) as t
                 where e.release_id = ? and e.class_kind = 'category'
             ) as matched
             where score > 0",
            [...$bindings, $release->id],
        );
    }

    /** pg_trgm word similarity above the configured threshold; `<%` uses the trigram index. */
    private function byTrigram(string $text, Release $release): array
    {
        return DB::transaction(function () use ($text, $release) {
            DB::select("select set_config('pg_trgm.word_similarity_threshold', ?, true)",
                [(string) config('icd.search.trigram_threshold')]);

            return $this->hits(MatchedVia::Fuzzy,
                "select e.*, word_similarity(?, e.title) as score
                 from icd_entities as e
                 where e.release_id = ? and e.class_kind = 'category' and ? <% e.title",
                [$text, $release->id, $text],
            );
        });
    }

    /**
     * One tsquery per word, English-stemmed. The last word also matches as a
     * prefix of the word as typed ("depr" finds "depressive"); the prefix is
     * not stemmed, so a misspelling such as "depresion" (stem "depres") does
     * not become a prefix of "depress" and is left to the fuzzy tier.
     * A last word that is an English stopword gets no prefix, since stopwords
     * are not in the vector; its empty tsquery drops out of `&&`, so "back
     * pain with" needs only "back" and "pain".
     *
     * @param  list<string>  $words
     * @return array{list<string>, list<string>}
     */
    private function terms(array $words): array
    {
        $sql = [];
        $bindings = [];
        $last = array_key_last($words);

        foreach ($words as $i => $word) {
            if ($i === $last) {
                $sql[] = "(to_tsquery('english', ?) || case when numnode(to_tsquery('english', ?)) = 0
                    then ''::tsquery else to_tsquery('simple', ? || ':*') end)";
                array_push($bindings, $word, $word, $word);
            } else {
                $sql[] = "to_tsquery('english', ?)";
                $bindings[] = $word;
            }
        }

        return [$sql, $bindings];
    }

    /** @return list<SearchHit> */
    private function hits(MatchedVia $via, string $sql, array $bindings): array
    {
        $hits = [];
        foreach (IcdEntity::fromQuery($sql, $bindings) as $entity) {
            $score = (float) $entity->score;
            unset($entity->score, $entity->search_vector);
            $hits[] = new SearchHit($entity, $via, $score);
        }

        return $hits;
    }
}
