<?php

use App\Icd\Diff\ChangeSet;
use App\Icd\Diff\ReleaseDiffer;
use App\Models\IcdEntity;
use App\Models\Release;
use App\Models\ReleaseChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->storage = sys_get_temp_dir().'/icd-storage-'.bin2hex(random_bytes(6));
    $this->tmp = sys_get_temp_dir().'/icd-local-'.bin2hex(random_bytes(6));
    mkdir($this->tmp);
    config(['icd.storage_path' => $this->storage]);
    Http::preventStrayRequests();
});

afterEach(function () {
    File::deleteDirectory($this->storage);
    File::deleteDirectory($this->tmp);
});

/** Places zip bytes where the import looks for a cached download. */
function cacheIcdZip(string $storage, string $version, string $bytes): string
{
    File::ensureDirectoryExists("{$storage}/{$version}");
    file_put_contents($path = "{$storage}/{$version}/release.zip", $bytes);

    return $path;
}

/**
 * Runs icd:import without console mocking, for messages checked for several
 * substrings: a mocked line satisfies only one expectsOutputToContain().
 *
 * @return array{int, string} exit code and output
 */
function runIcdImport(array $parameters): array
{
    $exitCode = Artisan::call('icd:import', $parameters);

    return [$exitCode, Artisan::output()];
}

function expectNothingImported(): void
{
    expect(Release::count())->toBe(0)
        ->and(IcdEntity::count())->toBe(0)
        ->and(ReleaseChange::count())->toBe(0);
}

// Import a release

it('downloads the release from the URL built from release_url and stores only the configured chapters', function () {
    config(['icd.release_url' => 'https://who.test/releases/{version}/release-file.zip']);
    $bytes = icdFixtureZipBytes('format-2026-01.txt');
    fakeWhoRelease('2026-01', $bytes);

    $this->artisan('icd:import', ['version' => '2026-01'])->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://who.test/releases/2026-01/release-file.zip');
    Http::assertSentCount(1);

    $release = Release::sole();
    expect($release->version)->toBe('2026-01')
        ->and($release->source_url)->toBe('https://who.test/releases/2026-01/release-file.zip')
        ->and($release->sha256)->toBe(hash_file('sha256', icdFixture('format-2026-01.txt')))
        ->and($release->version_stamp)->toBe('Version:2026 Jan 17 - 05:30 UTC')
        ->and($release->column_map['columns']['chapter'])->toBe('ChapterNo')
        ->and(IcdEntity::pluck('chapter')->unique()->values()->all())->toBe(['21'])
        ->and(IcdEntity::count())->toBe(7)
        ->and(IcdEntity::where('code', 'ZZ03')->exists())->toBeFalse()
        ->and(file_get_contents("{$this->storage}/2026-01/release.zip"))->toBe($bytes)
        ->and(icdFilesIn($this->storage))->toBe(['2026-01/release.zip']);
});

it('stores the tree with parent_id and parent_uri pointing at the same parent', function () {
    $this->artisan('icd:import', ['version' => '2026-01', '--file' => icdFixture('format-2026-01.txt')])->assertSuccessful();

    $zz010 = IcdEntity::where('code', 'ZZ01.0')->sole();

    expect($zz010->parent_uri)->toBe(SYNTHETIC_URI.'zz01')
        ->and($zz010->parent->linearization_uri)->toBe(SYNTHETIC_URI.'zz01')
        ->and($zz010->depth)->toBe(3)
        ->and($zz010->title)->toBe('Synthetic back pain, upper')
        ->and($zz010->coding_note)->toBe("Synthetic first paragraph of a coding note.\n\nSynthetic second paragraph of a coding note.")
        ->and(IcdEntity::whereNull('parent_id')->pluck('class_kind')->all())->toBe(['chapter']);
});

it('imports a local zip given with --file without downloading', function () {
    $path = makeIcdZip($this->tmp, ['SimpleTabulation-ICD-11-MMS-en.txt' => file_get_contents(icdFixture('format-2026-01.txt'))]);

    $this->artisan('icd:import', ['version' => '2026-01', '--file' => $path])->assertSuccessful();

    Http::assertNothingSent();
    expect(Release::sole()->source_url)->toBe($path)
        ->and(IcdEntity::count())->toBe(7)
        ->and(icdFilesIn($this->storage))->toBe([]);
});

it('imports an extracted .txt given with --file without downloading', function () {
    $this->artisan('icd:import', ['version' => '2026-01', '--file' => icdFixture('format-2026-01.txt')])->assertSuccessful();

    Http::assertNothingSent();
    expect(Release::sole()->sha256)->toBe(hash_file('sha256', icdFixture('format-2026-01.txt')))
        ->and(IcdEntity::count())->toBe(7);
});

it('fails before any download when the --file path does not exist', function () {
    $path = "{$this->tmp}/does-not-exist.zip";

    $this->artisan('icd:import', ['version' => '2026-01', '--file' => $path])
        ->expectsOutputToContain($path)
        ->assertFailed();

    Http::assertNothingSent();
    expectNothingImported();
});

it('rejects a version that is not YYYY-MM with a month from 01 to 12', function (string $version) {
    $this->artisan('icd:import', ['version' => $version])
        ->expectsOutputToContain('YYYY-MM')
        ->assertFailed();

    Http::assertNothingSent();
    expectNothingImported();
    expect(icdFilesIn($this->storage))->toBe([]);
})->with(['2026', '26-01', '2026-13', '2026-00', '2026-1', '2026-01/../x', "2026-01\n"]);

it('stops naming the URL when WHO responds 404', function () {
    fakeWhoRelease('2030-01', 404);

    $this->artisan('icd:import', ['version' => '2030-01'])
        ->expectsOutputToContain(icdReleaseUrl('2030-01'))
        ->assertFailed();

    Http::assertSentCount(1);
    expectNothingImported();
    expect(icdFilesIn($this->storage))->toBe([]);
});

it('stops naming the URL when WHO responds 5xx on every attempt', function () {
    fakeWhoRelease('2026-01', 503);

    $this->artisan('icd:import', ['version' => '2026-01'])
        ->expectsOutputToContain(icdReleaseUrl('2026-01'))
        ->assertFailed();

    Http::assertSentCount(3);
    expectNothingImported();
    expect(icdFilesIn($this->storage))->toBe([]);
});

it('imports when two 5xx responses are followed by a successful third attempt', function () {
    Http::fake([icdReleaseUrl('2026-01') => Http::sequence()
        ->push('Synthetic error page', 503)
        ->push('Synthetic error page', 502)
        ->push(icdFixtureZipBytes('format-2026-01.txt'), 200)]);

    $this->artisan('icd:import', ['version' => '2026-01'])->assertSuccessful();

    Http::assertSentCount(3);
    expect(IcdEntity::count())->toBe(7);
});

it('stops naming the URL when the download times out on every attempt', function () {
    Http::fake([icdReleaseUrl('2026-01') => Http::failedConnection('cURL error 28: Operation timed out')]);

    $this->artisan('icd:import', ['version' => '2026-01'])
        ->expectsOutputToContain(icdReleaseUrl('2026-01'))
        ->assertFailed();

    Http::assertSentCount(3);
    expectNothingImported();
    expect(icdFilesIn($this->storage))->toBe([]);
});

// Version stamp

it('fails quoting the version cell when the stamp cannot be read', function () {
    $this->artisan('icd:import', ['version' => '2026-01', '--file' => icdFixture('bad-version.txt')])
        ->expectsOutputToContain('Version:sometime')
        ->assertFailed();

    expectNothingImported();
});

it('stops when the stamp does not match the requested version', function () {
    [$exitCode, $output] = runIcdImport(['version' => '2026-01', '--file' => icdFixture('format-2025-01.txt')]);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('2026-01')->toContain('2025-01');

    expectNothingImported();
});

it('stops on a cached zip with the wrong stamp and keeps it', function () {
    $path = cacheIcdZip($this->storage, '2026-01', icdFixtureZipBytes('format-2025-01.txt'));

    [$exitCode, $output] = runIcdImport(['version' => '2026-01']);

    expect($exitCode)->not->toBe(0)
        ->and($output)->toContain('2026-01')->toContain('2025-01')->toContain($path)->toContain('--fresh');

    Http::assertNothingSent();
    expectNothingImported();
    expect(file_exists($path))->toBeTrue();
});

// Release file handling

it('reuses a cached zip without downloading', function () {
    $path = cacheIcdZip($this->storage, '2026-01', icdFixtureZipBytes('format-2026-01.txt'));

    $this->artisan('icd:import', ['version' => '2026-01'])->assertSuccessful();

    Http::assertNothingSent();
    expect(Release::sole()->source_url)->toBe($path)
        ->and(IcdEntity::count())->toBe(7);
});

it('downloads again with --fresh and replaces the cached zip', function () {
    $path = cacheIcdZip($this->storage, '2026-01', icdFixtureZipBytes('format-2026-01.txt'));
    $fresh = icdFixtureZipBytes('diff-2026-01.txt');
    fakeWhoRelease('2026-01', $fresh);

    $this->artisan('icd:import', ['version' => '2026-01', '--fresh' => true])->assertSuccessful();

    Http::assertSentCount(1);
    expect(file_get_contents($path))->toBe($fresh)
        ->and(IcdEntity::where('code', 'ZZ10')->exists())->toBeTrue();
});

it('keeps the cached zip when a --fresh download fails', function () {
    $cached = icdFixtureZipBytes('format-2026-01.txt');
    $path = cacheIcdZip($this->storage, '2026-01', $cached);
    fakeWhoRelease('2026-01', 'Synthetic HTML instead of a zip');

    $this->artisan('icd:import', ['version' => '2026-01', '--fresh' => true])->assertFailed();

    expectNothingImported();
    expect(file_get_contents($path))->toBe($cached)
        ->and(icdFilesIn($this->storage))->toBe(['2026-01/release.zip']);
});

it('reads only the data .txt from a zip that also holds an .xlsx and a readme.txt, without extracting', function () {
    fakeWhoRelease('2026-01', icdFixtureZipBytes('format-2026-01.txt', [
        'SimpleTabulation-ICD-11-MMS-en.xlsx' => 'Synthetic spreadsheet bytes',
        'readme.txt' => "Synthetic readme\twith\ttabs",
    ]));

    $this->artisan('icd:import', ['version' => '2026-01'])->assertSuccessful();

    expect(IcdEntity::count())->toBe(7)
        ->and(icdFilesIn($this->storage))->toBe(['2026-01/release.zip']);
});

it('stops, deleting the file, when the download is not a zip', function () {
    fakeWhoRelease('2026-01', '<html>Synthetic error page</html>');

    $this->artisan('icd:import', ['version' => '2026-01'])
        ->expectsOutputToContain('not a zip')
        ->assertFailed();

    expectNothingImported();
    expect(icdFilesIn($this->storage))->toBe([]);
});

it('stops naming the path, keeping the file, when a local or cached .zip is not a zip', function (string $origin) {
    $path = $origin === 'file' ? "{$this->tmp}/release.zip" : "{$this->storage}/2026-01/release.zip";
    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, 'Synthetic text, not a zip');

    $this->artisan('icd:import', ['version' => '2026-01'] + ($origin === 'file' ? ['--file' => $path] : []))
        ->expectsOutputToContain($path)
        ->assertFailed();

    Http::assertNothingSent();
    expectNothingImported();
    expect(file_exists($path))->toBeTrue();
})->with(['file', 'cached']);

it('stops on a truncated download and deletes it', function () {
    fakeWhoRelease('2026-01', substr(icdFixtureZipBytes('format-2026-01.txt'), 0, 200));

    $this->artisan('icd:import', ['version' => '2026-01'])
        ->expectsOutputToContain("{$this->storage}/2026-01/release.zip")
        ->assertFailed();

    expectNothingImported();
    expect(icdFilesIn($this->storage))->toBe([]);
});

it('stops naming the path on a truncated local or cached zip and keeps it', function (string $origin) {
    $path = $origin === 'file' ? "{$this->tmp}/release.zip" : "{$this->storage}/2026-01/release.zip";
    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, substr(icdFixtureZipBytes('format-2026-01.txt'), 0, 200));

    $this->artisan('icd:import', ['version' => '2026-01'] + ($origin === 'file' ? ['--file' => $path] : []))
        ->expectsOutputToContain($path)
        ->assertFailed();

    Http::assertNothingSent();
    expectNothingImported();
    expect(file_exists($path))->toBeTrue();
})->with(['file', 'cached']);

it('stops when the zip holds no data .txt', function () {
    fakeWhoRelease('2026-01', icdZipBytes(['readme.txt' => 'Synthetic readme', 'release.xlsx' => 'Synthetic spreadsheet bytes']));

    $this->artisan('icd:import', ['version' => '2026-01'])
        ->expectsOutputToContain('no data .txt')
        ->assertFailed();

    expectNothingImported();
    expect(icdFilesIn($this->storage))->toBe([]);
});

it('stops listing the candidates when the zip holds several data .txt files', function () {
    fakeWhoRelease('2026-01', icdZipBytes([
        'SimpleTabulation-ICD-11-MMS-en.txt' => file_get_contents(icdFixture('format-2026-01.txt')),
        'SimpleTabulation-ICD-11-MMS-sv.txt' => file_get_contents(icdFixture('format-2026-01.txt')),
        'readme.txt' => 'Synthetic readme',
    ]));

    $this->artisan('icd:import', ['version' => '2026-01'])
        ->expectsOutputToContain('SimpleTabulation-ICD-11-MMS-en.txt, SimpleTabulation-ICD-11-MMS-sv.txt')
        ->assertFailed();

    expectNothingImported();
    expect(icdFilesIn($this->storage))->toBe([]);
});

// Schema detection, record reassembly and hierarchy, end to end

it('fails on a malformed file and writes nothing', function (string $fixture, array $expected) {
    [$exitCode, $output] = runIcdImport(['version' => '2026-01', '--file' => icdFixture($fixture)]);

    expect($exitCode)->not->toBe(0);
    foreach ($expected as $text) {
        expect($output)->toContain($text);
    }

    expectNothingImported();
})->with([
    'missing required column' => ['missing-chapterno.txt', ['ChapterNo']],
    'missing version stamp' => ['missing-version.txt', ['no Version: stamp']],
    'too many fields' => ['too-many-fields.txt', ['line 4']],
    'file ends inside a record' => ['eof-inside-record.txt', ['line 5']],
    'Parent mismatch' => ['parent-mismatch.txt', ['ZZ01.0', 'ZZ02']],
    'Grouping mismatch' => ['grouping-mismatch.txt', ['ZZ01', 'BlockL1-ZZ1']],
    'depth jump' => ['depth-jump.txt', ['ZZ01.0']],
]);

it('imports a file with an unknown extra column and records it in column_map', function () {
    $this->artisan('icd:import', ['version' => '2026-01', '--file' => icdFixture('unknown-column.txt')])->assertSuccessful();

    expect(Release::sole()->column_map['unknown'])->not->toBe([])
        ->and(IcdEntity::count())->toBe(7);
});

// Re-runnable import (3.3, 3.4)

it('skips an unchanged file with a message and exit code 0', function () {
    $this->artisan('icd:import', ['version' => '2026-01', '--file' => icdFixture('format-2026-01.txt')])->assertSuccessful();
    $before = icdDatabaseState();
    $importedAt = Release::sole()->imported_at;
    $this->travel(5)->minutes();

    $this->artisan('icd:import', ['version' => '2026-01', '--file' => icdFixture('format-2026-01.txt')])
        ->expectsOutputToContain('already imported')
        ->assertSuccessful();

    expect(icdDatabaseState())->toBe($before)
        ->and(Release::sole()->imported_at->equalTo($importedAt))->toBeTrue();
});

it('re-imports an unchanged file with --force to an identical state', function (string $forced) {
    $this->artisan('icd:import', ['version' => '2025-01', '--file' => icdFixture('diff-2025-01.txt')])->assertSuccessful();
    $this->artisan('icd:import', ['version' => '2026-01', '--file' => icdFixture('diff-2026-01.txt')])->assertSuccessful();
    $before = icdDatabaseState();

    $this->artisan('icd:import', ['version' => $forced, '--file' => icdFixture("diff-{$forced}.txt"), '--force' => true])
        ->assertSuccessful();

    expect(icdDatabaseState())->toBe($before)
        ->and($before['changes'])->toHaveCount(7);
})->with(['2025-01', '2026-01']);

it('stores two releases side by side without changing the older one', function () {
    $this->artisan('icd:import', ['version' => '2025-01', '--file' => icdFixture('diff-2025-01.txt')])->assertSuccessful();
    $entities2025 = icdDatabaseState()['entities'];

    $this->artisan('icd:import', ['version' => '2026-01', '--file' => icdFixture('diff-2026-01.txt')])->assertSuccessful();
    $state = icdDatabaseState();

    expect(array_values(array_filter($state['entities'], fn ($e) => $e['version'] === '2025-01')))->toBe($entities2025)
        ->and(array_count_values(array_column($state['entities'], 'version')))->toBe(['2025-01' => 10, '2026-01' => 10])
        ->and(IcdEntity::where('code', 'ZZ11')->with('release')->get()->map(fn ($e) => [$e->release->version, $e->linearization_uri])->all())
        ->toBe([['2025-01', SYNTHETIC_URI.'zz11'], ['2026-01', SYNTHETIC_URI.'zz19']]);
});

it('stores the changes against the previous release after an import', function () {
    $this->artisan('icd:import', ['version' => '2025-01', '--file' => icdFixture('diff-2025-01.txt')])
        ->expectsOutputToContain('nothing to compare against')
        ->assertSuccessful();

    $this->artisan('icd:import', ['version' => '2026-01', '--file' => icdFixture('diff-2026-01.txt')])
        ->expectsOutputToContain('2025-01')
        ->assertSuccessful();

    expect(icdStoredChanges('2026-01'))->toBe([
        ['added', SYNTHETIC_URI.'zz19'],
        ['removed', SYNTHETIC_URI.'zz11'],
        ['recoded', SYNTHETIC_URI.'zz12'],
        ['moved', SYNTHETIC_URI.'zz13'],
        ['code_reused', SYNTHETIC_URI.'zz19'],
        ['retitled', SYNTHETIC_URI.'zz14'],
        ['retitled_cosmetic', SYNTHETIC_URI.'zz15'],
    ])
        ->and(icdStoredChanges('2025-01'))->toBe([])
        ->and(ReleaseChange::first()->previousRelease->version)->toBe('2025-01');
});

it('rolls back a failed --force re-import, leaving the previous state intact', function () {
    $this->artisan('icd:import', ['version' => '2026-01', '--file' => icdFixture('diff-2026-01.txt')])->assertSuccessful();
    $before = icdDatabaseState();

    app()->instance(ReleaseDiffer::class, new class extends ReleaseDiffer
    {
        public function diff(array $old, array $new): ChangeSet
        {
            throw new RuntimeException('Synthetic differ failure');
        }
    });

    // Unexpected errors are not ImportExceptions: they propagate, and Artisan
    // reports them and exits 1.
    expect(fn () => $this->artisan('icd:import', ['version' => '2026-01', '--file' => icdFixture('format-2026-01.txt'), '--force' => true])->run())
        ->toThrow(RuntimeException::class, 'Synthetic differ failure');

    expect(icdDatabaseState())->toBe($before);
});

// Changed file under an existing version (3.5)

it('refuses a changed file under an imported version, showing both hashes and the import date', function () {
    $this->artisan('icd:import', ['version' => '2026-01', '--file' => icdFixture('format-2026-01.txt')])->assertSuccessful();
    $before = icdDatabaseState();
    $release = Release::sole();

    [$exitCode, $output] = runIcdImport(['version' => '2026-01', '--file' => icdFixture('diff-2026-01.txt')]);

    expect($exitCode)->not->toBe(0)
        ->and($output)
        ->toContain($release->sha256)
        ->toContain(hash_file('sha256', icdFixture('diff-2026-01.txt')))
        ->toContain($release->imported_at->format('Y-m-d H:i'))
        ->toContain('--force');

    expect(icdDatabaseState())->toBe($before);
});

it('replaces a changed file with --force, printing the same-version diff and recomputing stored diffs', function () {
    $this->artisan('icd:import', ['version' => '2025-01', '--file' => icdFixture('diff-2025-01.txt')])->assertSuccessful();
    $this->artisan('icd:import', ['version' => '2026-01', '--file' => icdFixture('format-2026-01.txt')])->assertSuccessful();
    $this->artisan('icd:import', ['version' => '2027-01', '--file' => icdFixture('diff-2027-01.txt')])->assertSuccessful();

    $this->artisan('icd:import', ['version' => '2026-01', '--file' => icdFixture('diff-2026-01.txt'), '--force' => true])
        ->expectsOutputToContain('Changes against the stored 2026-01 snapshot (not stored)')
        ->expectsOutputToContain('removed ZZ01')
        ->expectsOutputToContain('added ZZ10')
        ->expectsOutputToContain('Recomputed the changes of 2027-01')
        ->assertSuccessful();

    $release = Release::where('version', '2026-01')->sole();

    expect($release->sha256)->toBe(hash_file('sha256', icdFixture('diff-2026-01.txt')))
        ->and(IcdEntity::where('release_id', $release->id)->where('code', 'ZZ10')->exists())->toBeTrue()
        ->and(IcdEntity::where('release_id', $release->id)->where('code', 'ZZ01')->exists())->toBeFalse()
        ->and(ReleaseChange::whereColumn('release_id', 'previous_release_id')->count())->toBe(0)
        ->and(array_count_values(array_column(icdStoredChanges('2026-01'), 0)))->toBe([
            'added' => 1, 'removed' => 1, 'recoded' => 1, 'moved' => 1,
            'code_reused' => 1, 'retitled' => 1, 'retitled_cosmetic' => 1,
        ])
        ->and(icdStoredChanges('2027-01'))->toBe([['retitled', SYNTHETIC_URI.'zz10']]);
});
