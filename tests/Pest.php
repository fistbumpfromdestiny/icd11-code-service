<?php

use App\Icd\Diff\EntitySnapshot;
use App\Icd\Import\ChapterFilter;
use App\Icd\Import\HierarchyBuilder;
use App\Icd\Import\ReleaseReader;
use App\Icd\Import\RowNormalizer;
use App\Icd\Import\Schema;
use App\Icd\Import\SchemaDetector;
use App\Models\Release;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

/** Linearization URI prefix of every synthetic fixture row. */
const SYNTHETIC_URI = 'http://id.who.int/icd/release/11/mms/synthetic/';

function icdFixture(string $name): string
{
    return __DIR__.'/Fixtures/icd/'.$name;
}

/** Imports a search fixture with icd:import and returns its release. */
function importSearchFixture(string $version = '2026-01', string $fixture = 'search.txt'): Release
{
    expect(Artisan::call('icd:import', ['version' => $version, '--file' => icdFixture($fixture)]))->toBe(0);

    return Release::where('version', $version)->firstOrFail();
}

/** @return array<int, list<string>> records keyed by their start line */
function readIcdRecords(ReleaseReader $reader, int $fieldCount): array
{
    return iterator_to_array($reader->records($fieldCount));
}

function detectIcdFixture(string $name): Schema
{
    return (new SchemaDetector)->detect(ReleaseReader::fromTxt(icdFixture($name))->header());
}

/** @return array<string, array<string, mixed>> normalized rows keyed by linearization URI */
function normalizeIcdFixture(string $name): array
{
    $reader = ReleaseReader::fromTxt(icdFixture($name));
    $schema = (new SchemaDetector)->detect($reader->header());

    $rows = [];
    foreach ($reader->records($schema->fieldCount) as $line => $fields) {
        $row = (new RowNormalizer)->normalize($fields, $schema, $line);
        $rows[$row['linearization_uri']] = $row;
    }

    return $rows;
}

/**
 * Builds a record's fields from values keyed by canonical column, placed by
 * the schema's column positions.
 *
 * @param  array<string, string>  $values
 * @return list<string>
 */
function icdFields(Schema $schema, array $values): array
{
    $fields = array_fill(0, $schema->fieldCount, '');
    foreach ($values as $column => $value) {
        $fields[$schema->indexes[$column]] = $value;
    }

    return $fields;
}

/** Writes a zip with the given entries into $dir and returns its path. */
function makeIcdZip(string $dir, array $entries, string $name = 'release.zip'): string
{
    $path = $dir.'/'.$name;
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $entry => $contents) {
        $zip->addFromString($entry, $contents);
    }
    $zip->close();

    return $path;
}

/**
 * Runs a fixture through reader, detector, normalizer, filter and builder.
 *
 * @return list<array<string, mixed>>
 */
function parseIcdFixture(string $name, array $chapters = ['06', '15', '21', '24']): array
{
    $reader = ReleaseReader::fromTxt(icdFixture($name));
    $schema = (new SchemaDetector)->detect($reader->header());
    $normalizer = new RowNormalizer;

    $rows = (function () use ($reader, $schema, $normalizer) {
        foreach ($reader->records($schema->fieldCount) as $line => $fields) {
            yield $normalizer->normalize($fields, $schema, $line);
        }
    })();

    return (new HierarchyBuilder)->build((new ChapterFilter($chapters))->filter($rows), $schema);
}

/** @return array<string, EntitySnapshot> a fixture's in-scope rows as diff snapshots, keyed by URI */
function icdSnapshots(string $name): array
{
    $snapshots = [];
    foreach (parseIcdFixture($name) as $row) {
        $snapshots[$row['linearization_uri']] = EntitySnapshot::fromArray($row);
    }

    return $snapshots;
}

/** A synthetic category snapshot; $key is the last URI segment. */
function diffSnapshot(string $key, ?string $code, string $title, ?string $parent = 'blockzz0', string $chapter = '21', ?string $blockId = null, string $kind = 'category'): EntitySnapshot
{
    return EntitySnapshot::fromArray([
        'linearization_uri' => SYNTHETIC_URI.$key,
        'code' => $code,
        'block_id' => $blockId,
        'title' => $title,
        'class_kind' => $kind,
        'chapter' => $chapter,
        'parent_uri' => $parent === null ? null : SYNTHETIC_URI.$parent,
    ]);
}

/** @return array<string, EntitySnapshot> keyed by linearization URI */
function diffSnapshots(EntitySnapshot ...$snapshots): array
{
    return array_column(array_map(fn ($s) => ['uri' => $s->linearizationUri, 's' => $s], $snapshots), 's', 'uri');
}

/** @return list<array{string, string}> [type, uri] pairs, in ChangeSet order */
function diffSummary(iterable $changes): array
{
    $pairs = [];
    foreach ($changes as $change) {
        $pairs[] = [$change->type->value, $change->linearizationUri];
    }

    return $pairs;
}

/** The download URL for a version, built from release_url as the import builds it. */
function icdReleaseUrl(string $version): string
{
    return str_replace('{version}', $version, config('icd.release_url'));
}

/** Zip bytes with the given entries. */
function icdZipBytes(array $entries): string
{
    $dir = sys_get_temp_dir().'/icd-zip-'.bin2hex(random_bytes(6));
    mkdir($dir);
    $bytes = file_get_contents(makeIcdZip($dir, $entries));
    File::deleteDirectory($dir);

    return $bytes;
}

/** Zip bytes laid out like a WHO release: the fixture as the data .txt, plus any extra entries. */
function icdFixtureZipBytes(string $fixture, array $extra = []): string
{
    return icdZipBytes(['SimpleTabulation-ICD-11-MMS-en.txt' => file_get_contents(icdFixture($fixture))] + $extra);
}

/** Fakes the WHO download for a version with zip bytes, or with an HTTP status. */
function fakeWhoRelease(string $version, string|int $response): void
{
    Http::fake([icdReleaseUrl($version) => is_int($response)
        ? Http::response('Synthetic error page', $response)
        : Http::response($response, 200, ['Content-Type' => 'application/zip'])]);
}

/** Files under a directory, relative to it; [] when it does not exist. */
function icdFilesIn(string $dir): array
{
    if (! is_dir($dir)) {
        return [];
    }

    return array_map(fn ($file) => $file->getRelativePathname(), File::allFiles($dir));
}

/**
 * Everything an import writes, without surrogate keys: releases, entities
 * with their parent resolved through parent_id, and stored changes.
 */
function icdDatabaseState(): array
{
    $rows = fn ($query) => $query->get()->map(fn ($row) => (array) $row)->all();

    return [
        'releases' => $rows(DB::table('releases')->orderBy('version')
            ->select('version', 'source_url', 'sha256', 'column_map', 'version_stamp')),
        'entities' => $rows(DB::table('icd_entities as e')
            ->join('releases as r', 'r.id', '=', 'e.release_id')
            ->leftJoin('icd_entities as p', 'p.id', '=', 'e.parent_id')
            ->orderBy('r.version')->orderBy('e.linearization_uri')
            ->select('r.version', 'e.linearization_uri', 'e.foundation_uri', 'e.code', 'e.block_id', 'e.title',
                'e.class_kind', 'e.chapter', 'e.depth', 'e.parent_uri', 'p.linearization_uri as parent_id_uri',
                'e.is_leaf', 'e.is_residual', 'e.is_primary_tabulation', 'e.coding_note')),
        'changes' => $rows(DB::table('release_changes as c')
            ->join('releases as r', 'r.id', '=', 'c.release_id')
            ->join('releases as pr', 'pr.id', '=', 'c.previous_release_id')
            ->orderBy('r.version')->orderBy('c.change_type')->orderBy('c.linearization_uri')
            ->select('r.version', 'pr.version as previous_version', 'c.change_type', 'c.linearization_uri', 'c.before', 'c.after')),
    ];
}

/** Change types stored for a release, as [type, uri] pairs. */
function icdStoredChanges(string $version): array
{
    return DB::table('release_changes as c')
        ->join('releases as r', 'r.id', '=', 'c.release_id')
        ->where('r.version', $version)
        ->orderBy('c.id')
        ->get(['c.change_type', 'c.linearization_uri'])
        ->map(fn ($c) => [$c->change_type, $c->linearization_uri])
        ->all();
}
