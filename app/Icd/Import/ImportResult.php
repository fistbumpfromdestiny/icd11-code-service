<?php

namespace App\Icd\Import;

use App\Icd\Diff\ChangeSet;
use App\Models\Release;

/**
 * What an import did, for the command to report.
 */
final readonly class ImportResult
{
    /**
     * @param  array<string, int>  $countsByChapter  entities stored per chapter
     * @param  ?ChangeSet  $changes  stored changes against $previous
     * @param  ?ChangeSet  $sameVersionChanges  changes against the replaced snapshot of the same version, not stored
     * @param  ?Release  $recomputedNext  the next newer release, whose stored changes were recomputed
     */
    public function __construct(
        public Release $release,
        public bool $skipped,
        public array $countsByChapter = [],
        public ?Release $previous = null,
        public ?ChangeSet $changes = null,
        public ?ChangeSet $sameVersionChanges = null,
        public ?Release $recomputedNext = null,
        public ?ChangeSet $nextChanges = null,
    ) {}
}
