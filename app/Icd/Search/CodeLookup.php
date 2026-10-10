<?php

namespace App\Icd\Search;

use App\Models\IcdEntity;
use App\Models\Release;

/**
 * Finds one category by code in a release, with its ancestors (root first)
 * and its children (in file order).
 */
class CodeLookup
{
    public function __construct(private Ancestors $ancestors) {}

    public function find(string $code, Release $release): ?IcdEntity
    {
        // All codes in the files are upper case, as in QueryClassifier.
        $entity = IcdEntity::where('release_id', $release->id)
            ->where('class_kind', 'category')
            ->where('code', mb_strtoupper($code))
            ->first();

        if ($entity === null) {
            return null;
        }

        $entity->setRelation('children', IcdEntity::where('parent_id', $entity->id)->orderBy('id')->get());
        $this->ancestors->load([$entity]);

        return $entity;
    }
}
