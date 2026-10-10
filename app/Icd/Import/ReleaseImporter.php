<?php

namespace App\Icd\Import;

use App\Icd\Diff\ChangeSet;
use App\Icd\Diff\EntitySnapshot;
use App\Icd\Diff\ReleaseDiffer;
use App\Models\Release;
use Illuminate\Support\Facades\DB;

/**
 * Imports one release file as a snapshot, in one transaction: replaces the
 * release's rows, then stores its diff against the previous release and
 * recomputes the next newer release's diff. The file is parsed in full
 * before anything is written.
 */
class ReleaseImporter
{
    private const CHUNK_SIZE = 500;

    public function __construct(
        private ReleaseDiffer $differ,
        private SchemaDetector $detector,
        private RowNormalizer $normalizer,
        private HierarchyBuilder $hierarchy,
    ) {}

    public function import(ResolvedRelease $source, string $version, bool $force): ImportResult
    {
        $reader = $source->reader();
        $schema = $this->detector->detect($reader->header());

        if ($schema->version !== $version) {
            $message = "Expected release {$version}, but the file is stamped \"{$schema->versionStamp}\", which is {$schema->version}.";

            throw new ImportException($source->cached
                ? "{$message} The cached file {$source->path} is not release {$version}: remove it or rerun with --fresh."
                : $message);
        }

        $sha256 = $source->sha256();
        $existing = Release::where('version', $version)->first();

        if ($existing !== null && ! $force) {
            if ($existing->sha256 === $sha256) {
                return new ImportResult($existing, skipped: true);
            }

            throw new ImportException(sprintf(
                "Release %s was imported on %s from a different file.\n  stored:    %s\n  this file: %s\nRerun with --force to replace it and see what changed.",
                $version, $existing->imported_at->format('Y-m-d H:i:s T'), $existing->sha256, $sha256,
            ));
        }

        $rows = $this->parse($reader, $schema);

        return DB::transaction(function () use ($source, $schema, $version, $sha256, $existing, $rows) {
            $replaced = $existing === null ? null : $this->snapshot($existing);

            $release = Release::updateOrCreate(['version' => $version], [
                'source_url' => $source->sourceUrl,
                'sha256' => $sha256,
                'column_map' => $schema->columnMap(),
                'version_stamp' => $schema->versionStamp,
                'imported_at' => now(),
            ]);

            $this->replaceEntities($release, $rows);
            $snapshot = [];
            foreach ($rows as $row) {
                $snapshot[$row['linearization_uri']] = EntitySnapshot::fromArray($row);
            }

            $sameVersionChanges = $replaced === null ? null : $this->differ->diff($replaced, $snapshot);

            $previous = Release::where('version', '<', $version)->orderByDesc('version')->first();
            $changes = $this->storeChanges($release, $previous, $previous === null ? [] : $this->snapshot($previous), $snapshot);

            $next = Release::where('version', '>', $version)->orderBy('version')->first();
            $nextChanges = $next === null ? null : $this->storeChanges($next, $release, $snapshot, $this->snapshot($next));

            return new ImportResult(
                release: $release,
                skipped: false,
                countsByChapter: array_count_values(array_column($rows, 'chapter')),
                previous: $previous,
                changes: $changes,
                sameVersionChanges: $sameVersionChanges,
                recomputedNext: $next,
                nextChanges: $nextChanges,
            );
        });
    }

    /** @return list<array<string, mixed>> in-scope rows in file order, with parent_uri */
    private function parse(ReleaseReader $reader, Schema $schema): array
    {
        $rows = (function () use ($reader, $schema) {
            foreach ($reader->records($schema->fieldCount) as $line => $fields) {
                yield $this->normalizer->normalize($fields, $schema, $line);
            }
        })();

        return $this->hierarchy->build((new ChapterFilter(config('icd.chapters')))->filter($rows), $schema);
    }

    /** @param list<array<string, mixed>> $rows */
    private function replaceEntities(Release $release, array $rows): void
    {
        DB::table('icd_entities')->where('release_id', $release->id)->delete();

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            DB::table('icd_entities')->insert(array_map(fn (array $row) => [
                'release_id' => $release->id,
                'linearization_uri' => $row['linearization_uri'],
                'foundation_uri' => $row['foundation_uri'],
                'code' => $row['code'],
                'block_id' => $row['block_id'],
                'title' => $row['title'],
                'class_kind' => $row['class_kind'],
                'chapter' => $row['chapter'],
                'depth' => $row['depth'],
                'parent_uri' => $row['parent_uri'],
                'is_leaf' => $row['is_leaf'],
                'is_residual' => $row['is_residual'],
                'is_primary_tabulation' => $row['is_primary_tabulation'],
                'coding_note' => $row['coding_note'],
            ], $chunk));
        }

        DB::update(
            'update icd_entities as e set parent_id = p.id
             from icd_entities as p
             where e.release_id = ? and p.release_id = e.release_id and p.linearization_uri = e.parent_uri',
            [$release->id],
        );

        $this->buildSearchVectors($release);
    }

    /**
     * Own title at weight A, the titles of every ancestor (categories, blocks,
     * chapter) at weight C, so "Lumbago with sciatica" is found for "back pain".
     */
    private function buildSearchVectors(Release $release): void
    {
        DB::update(
            "with recursive ancestors (id, ancestor_id) as (
                 select id, parent_id from icd_entities where release_id = ? and parent_id is not null
                 union all
                 select a.id, p.parent_id
                 from ancestors as a
                 join icd_entities as p on p.id = a.ancestor_id
                 where p.parent_id is not null
             ),
             context as (
                 select a.id, string_agg(p.title, ' ') as titles
                 from ancestors as a
                 join icd_entities as p on p.id = a.ancestor_id
                 group by a.id
             )
             update icd_entities as e
             set search_vector = setweight(to_tsvector('english', e.title), 'A')
                 || setweight(to_tsvector('english', coalesce(c.titles, '')), 'C')
             from icd_entities as s
             left join context as c on c.id = s.id
             where s.id = e.id and e.release_id = ?",
            [$release->id, $release->id],
        );
    }

    /**
     * Replaces a release's stored changes with its diff against $previous;
     * a release without a previous one has none.
     *
     * @param  array<string, EntitySnapshot>  $old
     * @param  array<string, EntitySnapshot>  $new
     */
    private function storeChanges(Release $release, ?Release $previous, array $old, array $new): ?ChangeSet
    {
        DB::table('release_changes')->where('release_id', $release->id)->delete();

        if ($previous === null) {
            return null;
        }

        $changes = $this->differ->diff($old, $new);

        foreach (array_chunk($changes->changes, self::CHUNK_SIZE) as $chunk) {
            DB::table('release_changes')->insert(array_map(fn ($change) => [
                'release_id' => $release->id,
                'previous_release_id' => $previous->id,
                'change_type' => $change->type->value,
                'linearization_uri' => $change->linearizationUri,
                'before' => $change->before === null ? null : json_encode($change->before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'after' => $change->after === null ? null : json_encode($change->after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ], $chunk));
        }

        return $changes;
    }

    /** @return array<string, EntitySnapshot> a stored release's entities, keyed by URI */
    private function snapshot(Release $release): array
    {
        $snapshot = [];
        foreach (DB::table('icd_entities')->where('release_id', $release->id)->get() as $row) {
            $snapshot[$row->linearization_uri] = EntitySnapshot::fromArray((array) $row);
        }

        return $snapshot;
    }
}
