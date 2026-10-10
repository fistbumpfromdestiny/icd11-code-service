<?php

namespace App\Icd\Search;

use App\Models\Release;

/**
 * Picks the release to search: the given version, or the latest imported
 * one. Latest means the highest version, not the most recent import.
 */
class ReleaseSelector
{
    public function select(?string $version): Release
    {
        if ($version !== null) {
            return Release::where('version', $version)->first()
                ?? throw UnknownReleaseException::for($version);
        }

        return Release::orderByDesc('version')->first()
            ?? throw new NoReleaseImportedException;
    }
}
