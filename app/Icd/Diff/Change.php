<?php

namespace App\Icd\Diff;

/**
 * One change of one entity. Before and after are the full snapshots, null
 * for an added or removed entity. For code_reused, before is the entity
 * that had the code and after the one that has it now.
 */
final readonly class Change
{
    public function __construct(
        public ChangeType $type,
        public string $linearizationUri,
        public ?array $before,
        public ?array $after,
    ) {}
}
