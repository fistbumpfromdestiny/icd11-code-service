<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * A category with its coding note, ancestors (root first) and children.
 */
class CodeResource extends IcdEntityResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'coding_note' => $this->coding_note,
            'ancestors' => IcdEntityResource::collection($this->ancestors),
            'children' => IcdEntityResource::collection($this->children),
        ];
    }
}
