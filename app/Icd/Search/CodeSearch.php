<?php

namespace App\Icd\Search;

use App\Models\IcdEntity;
use App\Models\Release;
use Illuminate\Support\Facades\DB;

/**
 * Searches one release (categories by default, see SearchFilters) in tiers:
 * code, title (all words), partial (some words), fuzzy. Each entity is
 * returned once, with its best tier. The fuzzy tier runs only when the
 * others found nothing. Each tier fetches all its matches, so ties are
 * decided by HitComparator rather than by SQL.
 *
 * User input reaches SQL only as bound parameters, and free-text words
 * contain only letters and digits (see QueryClassifier).
 */
class CodeSearch
{
    public function __construct(private QueryClassifier $classifier) {}

    /** @return list<SearchHit> */
    public function search(string $query, Release $release, int $limit = 10, SearchFilters $filters = new SearchFilters): array
    {
        $classified = $this->classifier->classify($query);
        $scope = $this->scope($release, $filters);
        $fuzzy = null;

        if ($classified->isCode) {
            $tiers = [fn () => $this->byCode($classified->code, $scope)];
        } elseif ($classified->words === []) {
            return [];
        } else {
            $words = $classified->words;
            $tiers = [
                fn () => $this->byAllWords($words, $scope),
                fn () => count($words) > 1 ? $this->bySomeWords($words, $scope) : [],
            ];
            $fuzzy = fn () => $this->byTrigram(implode(' ', $words), $scope);
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

        // Close matches only when nothing else matched, so they never pad
        // out real matches ("pain" is close to "partial").
        if ($hits === [] && $fuzzy !== null) {
            foreach ($fuzzy() as $hit) {
                $hits[$hit->entity->id] = $hit;
            }
        }

        $hits = array_values($hits);
        usort($hits, HitComparator::compare(...));

        return array_slice($hits, 0, $limit);
    }

    /**
     * The release and the filters, as a condition on alias `e` with its
     * bindings.
     *
     * @return array{string, list<mixed>}
     */
    private function scope(Release $release, SearchFilters $filters): array
    {
        [$sql, $bindings] = $filters->sql();

        return ["e.release_id = ? and {$sql}", [$release->id, ...$bindings]];
    }

    /**
     * @param  array{string, list<mixed>}  $scope
     * @return list<SearchHit> exact code scores 1, a longer code with the prefix 0
     */
    private function byCode(string $code, array $scope): array
    {
        [$where, $scopeBindings] = $scope;

        return $this->hits(MatchedVia::Code,
            "select e.*, (e.code = ?)::int as score
             from icd_entities as e
             where {$where} and e.code like ?",
            [$code, ...$scopeBindings, $code.'%'],
        );
    }

    /**
     * Every word must match the own or an ancestor title. The score is the
     * number of words found in the own title (weight A), so hits that match
     * the same way tie and HitComparator decides.
     *
     * @param  list<string>  $words
     * @param  array{string, list<mixed>}  $scope
     */
    private function byAllWords(array $words, array $scope): array
    {
        [$where, $scopeBindings] = $scope;
        [$sql, $bindings] = $this->terms($words);
        $query = '('.implode(' && ', $sql).')';
        $terms = 'array['.implode(', ', $sql).']';

        return $this->hits(MatchedVia::Title,
            "select e.*, (select count(*) from unnest(q.terms) as u(term) where ts_filter(e.search_vector, '{a}') @@ u.term) as score
             from icd_entities as e, (select {$query} as query, {$terms} as terms) as q
             where {$where} and e.search_vector @@ q.query",
            [...$bindings, ...$bindings, ...$scopeBindings],
        );
    }

    /**
     * Score is the number of words that match.
     *
     * @param  list<string>  $words
     * @param  array{string, list<mixed>}  $scope
     */
    private function bySomeWords(array $words, array $scope): array
    {
        [$where, $scopeBindings] = $scope;
        [$sql, $bindings] = $this->terms($words);
        $terms = 'array['.implode(', ', $sql).']';

        return $this->hits(MatchedVia::Partial,
            "select * from (
                 select e.*, (select count(*) from unnest(t.terms) as u(term) where e.search_vector @@ u.term) as score
                 from icd_entities as e, (select {$terms} as terms) as t
                 where {$where}
             ) as matched
             where score > 0",
            [...$bindings, ...$scopeBindings],
        );
    }

    /**
     * pg_trgm word similarity above the configured threshold; `<%` uses the trigram index.
     *
     * @param  array{string, list<mixed>}  $scope
     */
    private function byTrigram(string $text, array $scope): array
    {
        [$where, $scopeBindings] = $scope;

        return DB::transaction(function () use ($text, $where, $scopeBindings) {
            DB::select("select set_config('pg_trgm.word_similarity_threshold', ?, true)",
                [(string) config('icd.search.trigram_threshold')]);

            return $this->hits(MatchedVia::Fuzzy,
                "select e.*, word_similarity(?, e.title) as score
                 from icd_entities as e
                 where {$where} and ? <% e.title",
                [$text, ...$scopeBindings, $text],
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
