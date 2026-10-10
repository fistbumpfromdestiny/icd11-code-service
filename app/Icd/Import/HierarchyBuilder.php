<?php

namespace App\Icd\Import;

/**
 * Gives each row its parent from the title depth and file order (the file is
 * depth-first). Parent and Grouping1-5 are cross-checks only.
 */
class HierarchyBuilder
{
    /**
     * @param  iterable<array<string, mixed>>  $rows  normalized rows in file order
     * @return list<array<string, mixed>> the rows with parent_uri added
     */
    public function build(iterable $rows, Schema $schema): array
    {
        $checkParent = $schema->has('parent');
        $stack = [];
        $previousDepth = -1;
        $mismatches = [];
        $built = [];

        foreach ($rows as $row) {
            if ($row['depth'] > $previousDepth + 1) {
                throw new ImportException(sprintf(
                    '%s (line %d) is at depth %d, more than one level below the previous row (depth %d).',
                    self::label($row), $row['line'], $row['depth'], $previousDepth,
                ));
            }

            $stack = array_slice($stack, 0, $row['depth']);
            $parent = $stack === [] ? null : $stack[array_key_last($stack)];

            if ($checkParent && $row['parent_foundation_uri'] !== ($parent['foundation_uri'] ?? null)) {
                $mismatches[] = sprintf(
                    '%s (line %d): Parent is %s, derived parent has %s',
                    self::label($row), $row['line'],
                    $row['parent_foundation_uri'] ?? 'empty', $parent['foundation_uri'] ?? 'none',
                );
            }

            $ancestorBlocks = array_values(array_filter(array_column($stack, 'block_id')));
            if ($row['groupings'] !== $ancestorBlocks) {
                $mismatches[] = sprintf(
                    '%s (line %d): Grouping1-5 is [%s], derived ancestor blocks are [%s]',
                    self::label($row), $row['line'], implode(', ', $row['groupings']), implode(', ', $ancestorBlocks),
                );
            }

            $row['parent_uri'] = $parent['linearization_uri'] ?? null;
            $built[] = $row;
            $stack[] = $row;
            $previousDepth = $row['depth'];
        }

        if ($mismatches !== []) {
            throw new ImportException(
                "The hierarchy does not match the file's Parent or Grouping columns:\n  ".implode("\n  ", $mismatches),
            );
        }

        return $built;
    }

    private static function label(array $row): string
    {
        return $row['code'] ?? $row['block_id'] ?? "chapter {$row['chapter']}";
    }
}
