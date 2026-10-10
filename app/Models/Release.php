<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One imported WHO release, stored as its own snapshot.
 */
#[Fillable(['version', 'source_url', 'sha256', 'column_map', 'version_stamp', 'imported_at'])]
class Release extends Model
{
    /** @return HasMany<IcdEntity, $this> */
    public function entities(): HasMany
    {
        return $this->hasMany(IcdEntity::class);
    }

    /** @return HasMany<ReleaseChange, $this> */
    public function changes(): HasMany
    {
        return $this->hasMany(ReleaseChange::class);
    }

    protected function casts(): array
    {
        return [
            'column_map' => 'array',
            'imported_at' => 'datetime',
        ];
    }
}
