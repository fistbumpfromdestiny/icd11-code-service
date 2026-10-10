<?php

namespace App\Icd\Import;

use Generator;

/**
 * Keeps the rows of the configured chapters (config('icd.chapters')).
 */
class ChapterFilter
{
    /** @param list<string> $chapters */
    public function __construct(private array $chapters) {}

    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @return Generator<array<string, mixed>>
     */
    public function filter(iterable $rows): Generator
    {
        foreach ($rows as $row) {
            if (in_array($row['chapter'], $this->chapters, true)) {
                yield $row;
            }
        }
    }
}
