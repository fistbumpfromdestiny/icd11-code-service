<?php

namespace App\Icd\Import;

/**
 * Turns a record's fields into an entity row. Titles and codes are kept as
 * WHO wrote them, apart from the depth prefix and surrounding whitespace.
 */
class RowNormalizer
{
    private const DEPTH_PREFIX = '- ';

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public function normalize(array $fields, Schema $schema, int $line): array
    {
        $field = fn (string $column) => $schema->has($column) ? $fields[$schema->indexes[$column]] : '';
        $trimmed = fn (string $column) => self::nullIfEmpty(trim($field($column)));
        $boolean = fn (string $column) => self::boolean($field($column), $schema->headerNames[$column], $line);
        $required = fn (string $column) => $trimmed($column)
            ?? throw new ImportException("{$schema->headerNames[$column]} on line {$line} is empty.");

        $title = ltrim($field('title'));
        $depth = 0;
        while (str_starts_with($title, self::DEPTH_PREFIX)) {
            $title = substr($title, strlen(self::DEPTH_PREFIX));
            $depth++;
        }

        $groupings = [];
        foreach (range(1, 5) as $i) {
            if (($group = $trimmed("grouping{$i}")) !== null) {
                $groupings[] = $group;
            }
        }

        return [
            'line' => $line,
            'linearization_uri' => $required('linearization_uri'),
            'foundation_uri' => $trimmed('foundation_uri'),
            'code' => $trimmed('code'),
            'block_id' => $trimmed('block_id'),
            'title' => trim($title),
            'depth' => $depth,
            'class_kind' => $trimmed('class_kind'),
            'chapter' => $required('chapter'),
            'is_leaf' => $boolean('is_leaf'),
            'is_residual' => $boolean('is_residual'),
            'is_primary_tabulation' => $boolean('is_primary_tabulation'),
            'groupings' => $groupings,
            'coding_note' => self::nullIfEmpty($field('coding_note')),
            'parent_foundation_uri' => $trimmed('parent'),
        ];
    }

    private static function nullIfEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private static function boolean(string $value, string $column, int $line): ?bool
    {
        return match ($value) {
            'True' => true,
            'False' => false,
            '' => null,
            default => throw new ImportException("{$column} on line {$line} is \"{$value}\", expected True or False."),
        };
    }
}
