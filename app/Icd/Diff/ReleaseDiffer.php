<?php

namespace App\Icd\Diff;

use RuntimeException;

/**
 * Compares two release snapshots matched by linearization URI. A pure
 * function: one change record per change type that applies to a URI.
 * Not final, so tests can replace it.
 */
class ReleaseDiffer
{
    /**
     * @param  array<string, EntitySnapshot>  $old  keyed by linearization URI
     * @param  array<string, EntitySnapshot>  $new  keyed by linearization URI
     */
    public function diff(array $old, array $new): ChangeSet
    {
        $changes = [];

        foreach (array_diff_key($new, $old) as $uri => $entity) {
            $changes[] = new Change(ChangeType::Added, $uri, null, $entity->toArray());
        }

        foreach (array_diff_key($old, $new) as $uri => $entity) {
            $changes[] = new Change(ChangeType::Removed, $uri, $entity->toArray(), null);
        }

        foreach (array_intersect_key($old, $new) as $uri => $before) {
            $after = $new[$uri];
            $change = fn (ChangeType $type) => new Change($type, $uri, $before->toArray(), $after->toArray());

            if ($before->code !== $after->code) {
                $changes[] = $change(ChangeType::Recoded);
            }

            if ($before->parentUri !== $after->parentUri) {
                $changes[] = $change(ChangeType::Moved);
            }

            if ($before->title !== $after->title) {
                $changes[] = $change(self::isCosmetic($before->title, $after->title)
                    ? ChangeType::RetitledCosmetic
                    : ChangeType::Retitled);
            }
        }

        // Blocks have no code, so they never take part in code reuse.
        $oldByCode = self::byCode($old);
        foreach (self::byCode($new) as $code => $after) {
            $before = $oldByCode[$code] ?? null;

            if ($before !== null && $before->linearizationUri !== $after->linearizationUri) {
                $changes[] = new Change(ChangeType::CodeReused, $after->linearizationUri, $before->toArray(), $after->toArray());
            }
        }

        return new ChangeSet($changes);
    }

    /**
     * Two titles differ only cosmetically when they are equal after
     * lowercasing, replacing every non-letter, non-digit with a space and
     * collapsing whitespace.
     */
    public static function isCosmetic(string $before, string $after): bool
    {
        // Deliberately simple: no Unicode normalization or digit-only class.
        $fold = fn (string $title) => trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($title))
            ?? throw new RuntimeException('Cannot compare titles: '.preg_last_error_msg().'.'));

        return $fold($before) === $fold($after);
    }

    /**
     * @param  array<string, EntitySnapshot>  $snapshots
     * @return array<string, EntitySnapshot>
     */
    private static function byCode(array $snapshots): array
    {
        $byCode = [];
        foreach ($snapshots as $snapshot) {
            if ($snapshot->code !== null) {
                $byCode[$snapshot->code] = $snapshot;
            }
        }

        return $byCode;
    }
}
