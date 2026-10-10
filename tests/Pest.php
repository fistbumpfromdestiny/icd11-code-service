<?php

use App\Icd\Import\ChapterFilter;
use App\Icd\Import\HierarchyBuilder;
use App\Icd\Import\ReleaseReader;
use App\Icd\Import\RowNormalizer;
use App\Icd\Import\Schema;
use App\Icd\Import\SchemaDetector;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

/** Linearization URI prefix of every synthetic fixture row. */
const SYNTHETIC_URI = 'http://id.who.int/icd/release/11/mms/synthetic/';

function icdFixture(string $name): string
{
    return __DIR__.'/Fixtures/icd/'.$name;
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
