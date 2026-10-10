<?php

namespace App\Icd\Import;

/**
 * Maps header names to canonical columns, case-insensitively and with the
 * aliases seen in WHO's readme. Never relies on column positions.
 */
class SchemaDetector
{
    /** Canonical column => accepted header names (matched lowercased). */
    private const REQUIRED = [
        'linearization_uri' => ['Linearization URI', 'Linearization (release) URI'],
        'foundation_uri' => ['Foundation URI'],
        'code' => ['Code'],
        'block_id' => ['BlockId'],
        'title' => ['Title'],
        'class_kind' => ['ClassKind'],
        'is_residual' => ['IsResidual'],
        'chapter' => ['ChapterNo'],
        'is_leaf' => ['isLeaf'],
        'is_primary_tabulation' => ['Primary tabulation'],
        'grouping1' => ['Grouping1'],
        'grouping2' => ['Grouping2'],
        'grouping3' => ['Grouping3'],
        'grouping4' => ['Grouping4'],
        'grouping5' => ['Grouping5'],
    ];

    private const OPTIONAL = [
        'coding_note' => ['CodingNote'],
        'parent' => ['Parent'],
    ];

    private const IGNORED = ['depthinkind', 'browserlink'];

    private const MONTHS = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

    /** @param list<string> $header */
    public function detect(array $header): Schema
    {
        $lookup = [];
        foreach (self::REQUIRED + self::OPTIONAL as $column => $names) {
            foreach ($names as $name) {
                $lookup[strtolower($name)] = $column;
            }
        }

        $indexes = [];
        $headerNames = [];
        $ignored = [];
        $unknown = [];
        $stamps = [];
        $position = 0;

        foreach ($header as $cell) {
            $name = trim($cell);
            $key = strtolower($name);

            if (str_starts_with($key, 'version:')) {
                $stamps[] = $name;

                continue;
            }

            if (isset($lookup[$key])) {
                $column = $lookup[$key];
                if (isset($indexes[$column])) {
                    throw new ImportException("The header has both \"{$headerNames[$column]}\" and \"{$name}\" for the same column.");
                }
                $indexes[$column] = $position;
                $headerNames[$column] = $name;
            } elseif (in_array($key, self::IGNORED, true)) {
                $ignored[] = $name;
            } else {
                $unknown[] = $name;
            }

            $position++;
        }

        $missing = array_diff_key(self::REQUIRED, $indexes);
        if ($missing !== []) {
            $names = implode(', ', array_map(fn ($names) => $names[0], $missing));

            throw new ImportException("The header is missing required columns: {$names}.");
        }

        if ($stamps === []) {
            throw new ImportException('The header has no Version: stamp.');
        }

        if (count($stamps) > 1) {
            throw new ImportException('The header has several Version: stamps: "'.implode('", "', $stamps).'".');
        }

        return new Schema(
            indexes: $indexes,
            headerNames: $headerNames,
            ignored: $ignored,
            unknown: $unknown,
            fieldCount: $position,
            versionStamp: $stamps[0],
            version: $this->version($stamps[0]),
        );
    }

    /** Maps "Version:2026 Jan 17 - 05:30 UTC" to "2026-01". */
    private function version(string $stamp): string
    {
        if (! preg_match('/^version:\s*(\d{4})\s+([a-z]{3})\b/i', $stamp, $m)
            || ($month = array_search(strtolower($m[2]), self::MONTHS, true)) === false) {
            throw new ImportException("Cannot read the release version from the header cell \"{$stamp}\".");
        }

        return sprintf('%s-%02d', $m[1], $month + 1);
    }
}
