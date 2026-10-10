<?php

namespace App\Icd\Search;

use App\Models\IcdEntity;
use Illuminate\Database\Eloquent\Collection;

/**
 * Loads the ancestors of entities, root first, as their `ancestors`
 * relation. One recursive query for all entities, so a page of hits costs
 * one query rather than one per hit and level.
 */
class Ancestors
{
    /** @param iterable<IcdEntity> $entities */
    public function load(iterable $entities): void
    {
        $byId = [];
        foreach ($entities as $entity) {
            $byId[$entity->id] = $entity;
        }
        if ($byId === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($byId), '?'));
        $rows = IcdEntity::fromQuery(
            "with recursive chain (start_id, ancestor_id, hops) as (
                 select id, parent_id, 1 from icd_entities where id in ({$placeholders}) and parent_id is not null
                 union all
                 select c.start_id, p.parent_id, c.hops + 1
                 from chain as c join icd_entities as p on p.id = c.ancestor_id
                 where p.parent_id is not null
             )
             select a.*, c.start_id
             from chain as c join icd_entities as a on a.id = c.ancestor_id
             order by c.start_id, c.hops desc",
            array_keys($byId),
        );

        $grouped = [];
        foreach ($rows as $row) {
            $startId = $row->start_id;
            unset($row->start_id, $row->search_vector);
            $grouped[$startId][] = $row;
        }

        foreach ($byId as $id => $entity) {
            $entity->setRelation('ancestors', new Collection($grouped[$id] ?? []));
        }
    }
}
