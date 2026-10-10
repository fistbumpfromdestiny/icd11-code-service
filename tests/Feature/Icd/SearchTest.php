<?php

use App\Icd\Search\CodeSearch;
use App\Icd\Search\MatchedVia;
use App\Icd\Search\SearchHit;
use App\Models\Release;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

function importSearchFixture(string $version = '2026-01', string $fixture = 'search.txt'): Release
{
    expect(Artisan::call('icd:import', ['version' => $version, '--file' => icdFixture($fixture)]))->toBe(0);

    return Release::where('version', $version)->firstOrFail();
}

/** @return list<SearchHit> */
function searchFixture(string $query, int $limit = 10): array
{
    return app(CodeSearch::class)->search($query, Release::where('version', '2026-01')->firstOrFail(), $limit);
}

/** @param list<SearchHit> $hits */
function hitCodes(array $hits): array
{
    return array_map(fn (SearchHit $hit) => $hit->entity->code, $hits);
}

/** @param list<SearchHit> $hits */
function hitFor(array $hits, string $code): ?SearchHit
{
    foreach ($hits as $hit) {
        if ($hit->entity->code === $code) {
            return $hit;
        }
    }

    return null;
}

/** @param list<SearchHit> $hits */
function expectTiersInOrder(array $hits): void
{
    $tiers = array_map(fn (SearchHit $hit) => $hit->matchedVia->tier(), $hits);
    $sorted = $tiers;
    sort($sorted);

    expect($tiers)->toBe($sorted);
}

// Schema and import (5.1, 5.2)

it('creates pg_trgm, a GIN index on search_vector and a GIN trigram index on title', function () {
    $indexes = DB::table('pg_indexes')->where('tablename', 'icd_entities')->pluck('indexdef')->implode("\n");

    expect(DB::table('pg_extension')->where('extname', 'pg_trgm')->exists())->toBeTrue()
        ->and($indexes)->toContain('USING gin (search_vector)')
        ->and($indexes)->toContain('USING gin (title gin_trgm_ops)');
});

it('builds the search vector from the own title at weight A and every ancestor title at weight C', function () {
    $release = importSearchFixture();

    $vector = DB::table('icd_entities')
        ->where('release_id', $release->id)->where('code', 'ME84.20')
        ->value(DB::raw('search_vector::text'));

    // Own title "Lumbago with sciatica"; parent "Low back pain"; grandparent
    // "Spinal pain"; chapter 21 "Symptoms, signs or clinical findings, ...".
    expect($vector)->toMatch("/'sciatica':\\d+A/")
        ->and($vector)->toMatch("/'lumbago':\\d+A/")
        ->and($vector)->toMatch("/'back':\\d+C/")
        ->and($vector)->toMatch("/'spinal':\\d+C/")
        ->and($vector)->toMatch("/'symptom':\\d+C/")
        ->and($vector)->not->toMatch("/'back':\\d+A/");
});

it('builds a search vector for every entity of both search fixtures', function () {
    importSearchFixture('2025-01', 'search-2025.txt');
    importSearchFixture();

    expect(DB::table('icd_entities')->count())->toBe(102)
        ->and(DB::table('icd_entities')->whereNull('search_vector')->count())->toBe(0);
});

// Searchable entities

it('excludes blocks by default', function () {
    importSearchFixture();

    $hits = searchFixture('back pain');

    expect(array_map(fn (SearchHit $hit) => $hit->entity->block_id, $hits))->not->toContain('BlockL1-ZZ0')
        ->and(array_map(fn (SearchHit $hit) => $hit->entity->class_kind, $hits))->each->toBe('category');
});

// Code lookup

it('matches a code prefix, in code order', function () {
    importSearchFixture();

    $hits = searchFixture('6A7', limit: 50);
    $codes = hitCodes($hits);
    $sorted = $codes;
    usort($sorted, strcmp(...));

    expect($codes)->not->toBeEmpty()
        ->and($codes)->each->toStartWith('6A7')
        ->and(array_map(fn (SearchHit $hit) => $hit->matchedVia, $hits))->each->toBe(MatchedVia::Code)
        ->and($codes)->toBe($sorted)
        ->and(count($codes))->toBe(26);
});

it('puts the exact code first, then the codes starting with it in code order', function () {
    importSearchFixture();

    $hits = searchFixture('6A70', limit: 50);

    expect(hitCodes($hits))->toBe(['6A70', '6A70.0', '6A70.1', '6A70.2', '6A70.3', '6A70.4', '6A70.5', '6A70.6', '6A70.7', '6A70.Y', '6A70.Z'])
        ->and($hits[0]->matchedVia)->toBe(MatchedVia::Code);
});

it('matches lower-case codes like upper-case ones', function () {
    importSearchFixture();

    expect(hitCodes(searchFixture('6a7', limit: 50)))->toBe(hitCodes(searchFixture('6A7', limit: 50)));
});

// Full-text search with ancestor context

it('finds low back pain for a lay English phrase', function () {
    importSearchFixture();

    $hits = searchFixture('back pain');

    expect(array_slice(hitCodes($hits), 0, 3))->toContain('ME84.2')
        ->and(hitFor($hits, 'ME84.2')->matchedVia)->toBe(MatchedVia::Title);
});

it('matches words split between child and parent', function () {
    importSearchFixture();

    expect(hitCodes(searchFixture('back pain sciatica'))[0])->toBe('ME84.20');
});

it('matches a context word found only in the parent', function () {
    importSearchFixture();

    expect(array_slice(hitCodes(searchFixture('knee meniscus')), 0, 5))->toContain('FA33.0', 'FA33.1');
});

it('matches plural and possessive forms', function () {
    importSearchFixture();

    $codes = hitCodes(searchFixture('alzheimers onset'));

    expect(array_slice($codes, 0, 2))->toEqualCanonicalizing(['6D80.0', '6D80.1'])
        ->and($codes[2])->toBe('6D80.Z');
});

it('matches a partial last word', function () {
    importSearchFixture();

    $hits = array_slice(searchFixture('depr'), 0, 5);

    expect(hitCodes($hits))->toContain('6A70', '6A71')
        ->and(hitFor($hits, '6A70')->matchedVia)->toBe(MatchedVia::Title)
        ->and(hitFor($hits, '6A71')->matchedVia)->toBe(MatchedVia::Title);
});

it('ignores a stopword as the last word', function () {
    importSearchFixture();

    $hits = searchFixture('back pain with');

    expect(hitFor($hits, 'ME84.20')?->matchedVia)->toBe(MatchedVia::Title)
        ->and(hitFor($hits, 'ME84.2')?->matchedVia)->toBe(MatchedVia::Title);
});

// Partial-match fallback

it('falls back to results matching some of the words', function () {
    importSearchFixture();

    $hits = searchFixture('alzheimers onset memory');

    foreach (['6D80.0', '6D80.1', '6D80.Z'] as $code) {
        expect(hitFor($hits, $code)?->matchedVia)->toBe(MatchedVia::Partial);
    }
    expectTiersInOrder($hits);
});

// Typo tolerance

it('returns close matches for a misspelling', function () {
    importSearchFixture();

    $hit = hitFor(array_slice(searchFixture('depresion'), 0, 5), '6A70');

    expect($hit)->not->toBeNull()
        ->and($hit->matchedVia)->toBe(MatchedVia::Fuzzy);
});

it('applies the configured trigram threshold', function () {
    importSearchFixture();
    // word_similarity('depresion', 'Single episode depressive disorder') is
    // 0.6, pg_trgm's default threshold, so only a raised threshold shows
    // that the configured value is used.
    config(['icd.search.trigram_threshold' => 0.95]);

    $hits = searchFixture('depresion');

    expect(array_filter($hits, fn (SearchHit $hit) => $hit->matchedVia === MatchedVia::Fuzzy))->toBe([]);
});

// Ranking and explanation

it('returns each entity once with its best match', function () {
    importSearchFixture();

    $hits = searchFixture('depressive', limit: 50);

    expect(array_count_values(hitCodes($hits))['6A70'])->toBe(1)
        ->and(hitFor($hits, '6A70')->matchedVia)->toBe(MatchedVia::Title);
    expectTiersInOrder($hits);
});

// Chapters treated equally

it('returns each hit\'s chapter', function () {
    importSearchFixture();

    $hits = searchFixture('pain');

    expect($hits)->not->toBeEmpty()
        ->and(array_map(fn (SearchHit $hit) => $hit->entity->chapter, $hits))->each->toBeIn(['06', '15', '21', '24']);
});

// Query safety

it('never passes operator characters to PostgreSQL', function (string $query) {
    importSearchFixture();

    expect(searchFixture($query))->toBeArray();
})->with(['back & (pain !', "'depr':* | !x <-> y", '&| !:*', 'pain with', 'with']);
