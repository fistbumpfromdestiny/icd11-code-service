<?php

namespace App\Console\Commands;

use App\Icd\Diff\Change;
use App\Icd\Diff\ChangeSet;
use App\Icd\Diff\ChangeType;
use App\Icd\Import\ImportException;
use App\Icd\Import\ImportResult;
use App\Icd\Import\ReleaseImporter;
use App\Icd\Import\ReleaseSource;
use Illuminate\Console\Command;

class ImportIcdRelease extends Command
{
    protected $signature = 'icd:import
        {version : The release as YYYY-MM, e.g. 2026-01}
        {--file= : A local release .zip or extracted .txt, path inside the container}
        {--fresh : Download again even if the release is cached; a valid new zip replaces the cached one before its stamp is checked}
        {--force : Re-import an already imported version}
        {--details : List every change with its code and title before and after}';

    protected $description = 'Import a WHO ICD-11 MMS release (configured chapters only)';

    public function handle(ReleaseSource $source, ReleaseImporter $importer): int
    {
        $version = $this->argument('version');

        try {
            $file = $source->resolve($version, $this->option('file'), (bool) $this->option('fresh'));
            $result = $importer->import($file, $version, (bool) $this->option('force'));
        } catch (ImportException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->report($result);

        return self::SUCCESS;
    }

    private function report(ImportResult $result): void
    {
        $release = $result->release;

        if ($result->skipped) {
            $this->info("Release {$release->version} is already imported from this file (sha256 {$release->sha256}); nothing written. Use --force to re-import.");

            return;
        }

        $this->info(sprintf('Imported %s from %s: %d entities.', $release->version, $release->source_url, array_sum($result->countsByChapter)));
        foreach ($result->countsByChapter as $chapter => $count) {
            $this->line("  chapter {$chapter}: {$count}");
        }

        if ($result->sameVersionChanges !== null) {
            $this->line("Changes against the stored {$release->version} snapshot (not stored):");
            $this->listChanges($result->sameVersionChanges);
        }

        if ($result->previous === null) {
            $this->line('No earlier release is imported; nothing to compare against.');
        } else {
            $this->line("Changes against {$result->previous->version}:");
            $this->summary($result->changes);
        }

        if ($result->recomputedNext !== null) {
            $this->line("Recomputed the changes of {$result->recomputedNext->version} against {$release->version}:");
            $this->summary($result->nextChanges);
        }
    }

    /** Counts per change type and configured chapter, reused codes, and with --details every change. */
    private function summary(ChangeSet $changes): void
    {
        $chapters = config('icd.chapters');

        $rows = [];
        foreach ($changes->countsByTypeAndChapter() as $type => $counts) {
            $perChapter = array_map(fn (string $chapter) => $counts[$chapter] ?? 0, $chapters);
            $rows[] = [$type, ...$perChapter, array_sum($perChapter)];
        }
        $this->table(['Change', ...$chapters, 'Total'], $rows);

        foreach ($changes->changes as $change) {
            if ($change->type === ChangeType::CodeReused) {
                $this->warn("Code {$change->after['code']} now belongs to a different entity: {$change->before['linearization_uri']} -> {$change->after['linearization_uri']}");
            }
        }

        if ($this->option('details')) {
            $this->listChanges($changes);
        }
    }

    private function listChanges(ChangeSet $changes): void
    {
        if ($changes->changes === []) {
            $this->line('  none');
        }

        foreach ($changes->changes as $change) {
            $this->line('  '.self::describe($change));
        }
    }

    /** One change as "type before -> after", each side the entity's code and title. */
    private static function describe(Change $change): string
    {
        $before = self::entity($change->before);
        $after = self::entity($change->after);

        return match ($change->type) {
            ChangeType::Moved => "moved {$before} -> {$after} (parent ".($change->before['parent_uri'] ?? '(none)').' -> '.($change->after['parent_uri'] ?? '(none)').')',
            ChangeType::CodeReused => "code_reused {$before} ({$change->before['linearization_uri']}) -> {$after} ({$change->after['linearization_uri']})",
            default => "{$change->type->value} {$before} -> {$after}",
        };
    }

    private static function entity(?array $entity): string
    {
        if ($entity === null) {
            return '(none)';
        }

        return ($entity['code'] ?? $entity['block_id'] ?? "chapter {$entity['chapter']}").' "'.$entity['title'].'"';
    }
}
