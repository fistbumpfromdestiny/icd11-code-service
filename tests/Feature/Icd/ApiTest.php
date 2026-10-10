<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

const API_HIT_FIELDS = ['code', 'block_id', 'title', 'class_kind', 'chapter', 'breadcrumb', 'is_leaf',
    'is_residual', 'primary_coding_allowed', 'matched_via', 'score'];

/** Code, or block id for a block, of every hit. */
function apiHitIds(TestResponse $response): array
{
    return array_map(fn (array $hit) => $hit['code'] ?? $hit['block_id'], $response->json('data'));
}

function apiHit(TestResponse $response, string $id): ?array
{
    return collect($response->json('data'))->first(fn (array $hit) => ($hit['code'] ?? $hit['block_id']) === $id);
}

// Search endpoint

it('returns hits with every field, the release used and the attribution', function () {
    importSearchFixture();

    $response = $this->getJson('/api/search?q=back+pain')
        ->assertOk()
        ->assertJsonPath('meta.release', '2026-01')
        ->assertJsonPath('meta.attribution', config('icd.attribution'));

    expect($response->json('data'))->not->toBeEmpty();
    foreach ($response->json('data') as $hit) {
        expect(array_keys($hit))->toEqualCanonicalizing(API_HIT_FIELDS);
    }
});

it('returns the breadcrumb root first, without the hit itself', function () {
    importSearchFixture();

    $hit = apiHit($this->getJson('/api/search?q=back+pain+sciatica'), 'ME84.20');

    expect($hit['breadcrumb'][0])->toBe('Symptoms, signs or clinical findings, not elsewhere classified')
        ->and(array_slice($hit['breadcrumb'], -2))->toBe(['Spinal pain', 'Low back pain']);
});

it('returns a block hit with code null', function () {
    importSearchFixture();

    $hit = apiHit($this->getJson('/api/search?q=back+pain&include_blocks=1'), 'BlockL1-ZZ0');

    expect($hit)->not->toBeNull()
        ->and($hit['code'])->toBeNull()
        ->and($hit['block_id'])->toBe('BlockL1-ZZ0')
        ->and($hit['class_kind'])->toBe('block');
});

it('returns an empty data array when nothing matches', function () {
    importSearchFixture();

    $this->getJson('/api/search?q=xqzvw')->assertOk()->assertExactJson([
        'data' => [],
        'meta' => ['release' => '2026-01', 'attribution' => config('icd.attribution')],
    ]);
});

it('applies the default limit of 10 and a given limit', function () {
    importSearchFixture();

    expect($this->getJson('/api/search?q=6A7')->json('data'))->toHaveCount(10)
        ->and($this->getJson('/api/search?q=6A7&limit=50')->json('data'))->toHaveCount(26);
});

it('rejects invalid input with 422', function (string $query, string $field) {
    $this->getJson('/api/search?'.$query)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'q missing' => ['', 'q'],
    'q short after trimming' => ['q='.rawurlencode('  a '), 'q'],
    'q too long' => ['q='.str_repeat('a', 201), 'q'],
    'limit 0' => ['q=pain&limit=0', 'limit'],
    'limit 51' => ['q=pain&limit=51', 'limit'],
    'chapter 07' => ['q=pain&chapter=07', 'chapter'],
    'release 2026' => ['q=pain&release=2026', 'release'],
    'release month 13' => ['q=pain&release=2026-13', 'release'],
    'leaf_only not boolean' => ['q=pain&leaf_only=maybe', 'leaf_only'],
]);

it('accepts query operator characters', function () {
    importSearchFixture();

    $this->getJson('/api/search?q=back+%26+(pain+!')->assertOk();
});

it('filters out supplementary-only codes with primary_only', function () {
    importSearchFixture();

    expect(apiHitIds($this->getJson('/api/search?q=back+pain')))->toContain('ZZ01')
        ->and(apiHitIds($this->getJson('/api/search?q=back+pain&primary_only=1')))->not->toContain('ZZ01');
});

it('returns only leaves with leaf_only', function () {
    importSearchFixture();

    $response = $this->getJson('/api/search?q=back+pain&leaf_only=1')->assertOk();

    expect(array_column($response->json('data'), 'is_leaf'))->each->toBeTrue()
        ->and(apiHitIds($response))->toContain('ME84.20')
        ->and(apiHitIds($response))->not->toContain('ME84.2');
});

it('returns one chapter with chapter', function () {
    importSearchFixture();

    $response = $this->getJson('/api/search?q=pain&chapter=21')->assertOk();

    expect(array_column($response->json('data'), 'chapter'))->each->toBe('21')
        ->and(apiHitIds($response))->toContain('ME84.2')
        ->and(apiHitIds($response))->not->toContain('ZZ02');
});

it('includes blocks with include_blocks', function () {
    importSearchFixture();

    expect(apiHitIds($this->getJson('/api/search?q=back+pain')))->not->toContain('BlockL1-ZZ0')
        ->and(apiHitIds($this->getJson('/api/search?q=back+pain&include_blocks=1')))->toContain('BlockL1-ZZ0');
});

// Code endpoint

it('returns a code with its ancestors root first, its children and its primary coding flag', function () {
    importSearchFixture();

    $response = $this->getJson('/api/codes/ME84.2')->assertOk()->assertJsonPath('data.code', 'ME84.2');
    $ancestors = $response->json('data.ancestors');

    expect($ancestors[0])->toMatchArray(['class_kind' => 'chapter', 'chapter' => '21'])
        ->and(end($ancestors)['code'])->toBe('ME84')
        ->and(array_column($response->json('data.children'), 'code'))->toContain('ME84.20')
        ->and($response->json('data.primary_coding_allowed'))->toBeTrue();
});

it('looks up a lower-case code', function () {
    importSearchFixture();

    $this->getJson('/api/codes/me84.2')->assertOk()->assertJsonPath('data.code', 'ME84.2');
});

it('shows the coding note of the selected release', function () {
    importSearchFixture('2025-01', 'search-2025.txt');
    importSearchFixture();
    $note = collect(normalizeIcdFixture('search.txt'))->firstWhere('code', '6D80.0')['coding_note'];

    expect($note)->toStartWith('This category should never be used in primary tabulation.');
    $this->getJson('/api/codes/6D80.0')->assertOk()
        ->assertJsonPath('meta.release', '2026-01')
        ->assertJsonPath('data.coding_note', $note);
    $this->getJson('/api/codes/6D80.0?release=2025-01')->assertOk()
        ->assertJsonPath('meta.release', '2025-01')
        ->assertJsonPath('data.coding_note', null);
});

it('returns 404 for an unknown code', function () {
    importSearchFixture();

    $this->getJson('/api/codes/ZZ99')->assertNotFound();
});

it('rejects an invalid release on the code endpoint with 422', function () {
    $this->getJson('/api/codes/6A70?release=2026')->assertUnprocessable()->assertJsonValidationErrors('release');
});

// Release errors

it('returns 404 naming a release that is not imported', function (string $url) {
    importSearchFixture();

    $this->getJson($url)->assertNotFound()->assertJsonPath('message', 'Release 2030-01 is not imported.');
})->with(['/api/search?q=back+pain&release=2030-01', '/api/codes/6A70?release=2030-01']);

it('returns 503 when no release is imported', function (string $url) {
    $this->getJson($url)->assertStatus(503)->assertJsonPath('message', 'No ICD release imported');
})->with(['/api/search?q=back+pain', '/api/codes/6A70']);

// Attribution

it('includes the WHO attribution on every endpoint', function () {
    importSearchFixture();

    expect(config('icd.attribution'))->toContain('World Health Organization')->toContain('CC BY-ND 3.0 IGO');
    foreach (['/api/search?q=back+pain', '/api/codes/ME84.2'] as $url) {
        $this->getJson($url)->assertOk()->assertJsonPath('meta.attribution', config('icd.attribution'));
    }
});
