<?php

namespace App\Icd\Diff;

/**
 * The changes between two releases, ordered by change type, then URI.
 */
final readonly class ChangeSet
{
    /** @var list<Change> */
    public array $changes;

    /** @param list<Change> $changes */
    public function __construct(array $changes)
    {
        $order = array_flip(array_map(fn (ChangeType $type) => $type->value, ChangeType::cases()));

        usort($changes, fn (Change $a, Change $b) => [$order[$a->type->value], $a->linearizationUri]
            <=> [$order[$b->type->value], $b->linearizationUri]);

        $this->changes = $changes;
    }

    /** @return array<string, int> every change type, in order, with its count */
    public function countsByType(): array
    {
        $counts = array_fill_keys(array_map(fn (ChangeType $type) => $type->value, ChangeType::cases()), 0);

        foreach ($this->changes as $change) {
            $counts[$change->type->value]++;
        }

        return $counts;
    }
}
