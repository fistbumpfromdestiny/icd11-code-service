<?php

namespace App\Icd\Search;

/**
 * Restricts which entities a search returns. Categories only by default;
 * chapters are never returned.
 */
final readonly class SearchFilters
{
    public function __construct(
        public ?string $chapter = null,
        public bool $leafOnly = false,
        public bool $primaryOnly = false,
        public bool $includeBlocks = false,
    ) {}

    /** @return array{string, list<string>} a condition on alias `e`, with its bindings */
    public function sql(): array
    {
        $sql = $this->includeBlocks
            ? "e.class_kind in ('category', 'block')"
            : "e.class_kind = 'category'";
        $bindings = [];

        if ($this->chapter !== null) {
            $sql .= ' and e.chapter = ?';
            $bindings[] = $this->chapter;
        }
        if ($this->leafOnly) {
            $sql .= ' and e.is_leaf';
        }
        if ($this->primaryOnly) {
            $sql .= ' and e.is_primary_tabulation';
        }

        return [$sql, $bindings];
    }
}
