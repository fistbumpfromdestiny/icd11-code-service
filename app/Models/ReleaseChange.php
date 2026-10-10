<?php

namespace App\Models;

use App\Icd\Diff\ChangeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of one entity between a release and the previous one.
 */
class ReleaseChange extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<Release, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /** @return BelongsTo<Release, $this> */
    public function previousRelease(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'previous_release_id');
    }

    protected function casts(): array
    {
        return [
            'change_type' => ChangeType::class,
            'before' => 'array',
            'after' => 'array',
        ];
    }
}
