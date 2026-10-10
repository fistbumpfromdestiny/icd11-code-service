<?php

namespace App\Http\Resources;

use App\Icd\Search\SearchHit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A search hit: the entity, how it matched and its score within that tier.
 *
 * @property SearchHit $resource
 */
class SearchHitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...(new IcdEntityResource($this->resource->entity))->toArray($request),
            'matched_via' => $this->resource->matchedVia->value,
            'score' => round($this->resource->score, 3),
        ];
    }
}
