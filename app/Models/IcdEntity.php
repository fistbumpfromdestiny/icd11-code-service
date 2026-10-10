<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A chapter, block or category in one release.
 */
class IcdEntity extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<Release, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /** @return BelongsTo<IcdEntity, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(IcdEntity::class, 'parent_id');
    }

    protected function casts(): array
    {
        return [
            'is_leaf' => 'boolean',
            'is_residual' => 'boolean',
            'is_primary_tabulation' => 'boolean',
        ];
    }
}
