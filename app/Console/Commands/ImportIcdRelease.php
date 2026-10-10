<?php

namespace App\Console\Commands;

use App\Icd\Diff\Change;
use App\Icd\Diff\ChangeSet;
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
        {--force : Re-import an already imported version}';

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
            $this->line("Changes against {$result->previous->version}: ".self::counts($result->changes));
        }

        if ($result->recomputedNext !== null) {
            $this->line("Recomputed the changes of {$result->recomputedNext->version} against {$release->version}: ".self::counts($result->nextChanges));
        }
    }

    private function listChanges(ChangeSet $changes): void
    {
        if ($changes->changes === []) {
            $this->line('  none');
        }

        foreach ($changes->changes as $change) {
            $this->line("  {$change->type->value} ".self::label($change));
        }
    }

    private static function label(Change $change): string
    {
        $entity = $change->after ?? $change->before;

        return ($entity['code'] ?? $entity['block_id'] ?? "chapter {$entity['chapter']}").' '.$entity['title'];
    }

    private static function counts(ChangeSet $changes): string
    {
        $parts = [];
        foreach ($changes->countsByType() as $type => $count) {
            $parts[] = "{$type} {$count}";
        }

        return implode(', ', $parts);
    }
}
