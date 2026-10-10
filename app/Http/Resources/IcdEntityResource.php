<?php

namespace App\Http\Resources;

use App\Models\IcdEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A chapter, block or category as served. Blocks have code null; chapters
 * have code and block_id null. breadcrumb (ancestor titles, root first) is
 * present when the ancestors are loaded.
 *
 * @mixin IcdEntity
 */
class IcdEntityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'block_id' => $this->block_id,
            'title' => $this->title,
            'class_kind' => $this->class_kind,
            'chapter' => $this->chapter,
            'breadcrumb' => $this->whenLoaded('ancestors', fn () => $this->ancestors->pluck('title')->all()),
            'is_leaf' => $this->is_leaf,
            'is_residual' => $this->is_residual,
            'primary_coding_allowed' => $this->is_primary_tabulation,
        ];
    }
}
