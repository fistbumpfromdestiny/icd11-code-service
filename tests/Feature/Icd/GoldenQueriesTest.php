<?php

/**
 * Golden queries (7.1, 7.2): real queries against the real 2026-01 release,
 * reporting recall@5 for the expected hits and every miss with its top 3.
 *
 * Excluded from the default suite (see phpunit.xml); run it with
 * `php artisan test --group=golden`. It imports the release zips from
 * storage/app/icd/{version}/release.zip and is skipped when 2026-01 is not
 * there. It never downloads.
 */

use App\Icd\Search\CodeSearch;
use App\Icd\Search\SearchHit;
use App\Models\Release;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

/** Query => code that must be in the top 5. */
const GOLDEN_HITS = [
    '6A70' => '6A70',
    '6a7' => '6A70',
    'depression' => '6A70',
    'depresion' => '6A70',
    'depr' => '6A70',
    'anxiety' => '6B00',
    'panic' => '6B01',
    'burnout' => 'QD85',
    'low back pain' => 'ME84.2',
    'back pain sciatica' => 'ME84.20',
    'sciatca' => 'ME84.3',
    'knee meniscus' => 'FA33.0',
    'alzheimers onset' => '6D80.0',
    'alzhiemer' => '6D80',
    'schizofrenia' => '6A20',
    'chronic pain' => 'MG30',
    'tennis elbow' => 'FB55.1',
];

/** Query => code we know is not found; reported, never fails. */
const GOLDEN_MISSES = [
    'fibromialgia' => 'MG30.01',
    'anxeity' => '6B00',
    'frozen shoulder' => 'FB53.0',
];

it('finds the golden queries in the top 5 of the real 2026-01 release', function () {
    // The real files, not icd.storage_path, which other tests point elsewhere.
    $zip = fn (string $version) => storage_path("app/icd/{$version}/release.zip");

    if (! is_file($zip('2026-01'))) {
        $this->markTestSkipped('No release zip at '.$zip('2026-01'));
    }

    foreach (['2025-01', '2026-01'] as $version) {
        if (is_file($zip($version))) {
            expect(Artisan::call('icd:import', ['version' => $version, '--file' => $zip($version)]))->toBe(0);
        }
    }

    $release = Release::where('version', '2026-01')->firstOrFail();
    $search = app(CodeSearch::class);
    $describe = fn (SearchHit $hit) => "{$hit->entity->code} {$hit->entity->title} ({$hit->matchedVia->value})";

    $missedHits = [];
    $report = [];
    $foundMisses = [];

    foreach ([GOLDEN_HITS, GOLDEN_MISSES] as $set => $queries) {
        foreach ($queries as $query => $code) {
            $hits = $search->search((string) $query, $release, 5);
            $found = in_array($code, array_map(fn (SearchHit $hit) => $hit->entity->code, $hits), true);

            if ($found) {
                if ($set === 1) {
                    $foundMisses[] = "\"{$query}\" -> {$code}";
                }

                continue;
            }
            if ($set === 0) {
                $missedHits[] = (string) $query;
            }
            $top3 = array_map($describe, array_slice($hits, 0, 3)) ?: ['(no hits)'];
            $report[] = sprintf('  [%s] "%s" -> %s; top 3: %s',
                $set === 0 ? 'expected hit' : 'expected miss', $query, $code, implode('; ', $top3));
        }
    }

    $total = count(GOLDEN_HITS);
    $foundCount = $total - count($missedHits);
    $lines = [
        '',
        'Golden queries against 2026-01',
        sprintf('recall@5: %d/%d (%.1f%%)', $foundCount, $total, 100 * $foundCount / $total),
        'Misses:',
        ...($report ?: ['  none']),
    ];
    if ($foundMisses !== []) {
        $lines[] = 'Expected misses that were found: '.implode(', ', $foundMisses);
    }
    fwrite(STDERR, implode(PHP_EOL, $lines).PHP_EOL);

    expect($missedHits)->toBe([]);
})->group('golden');
