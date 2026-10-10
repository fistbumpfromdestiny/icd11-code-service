<?php

namespace App\Icd\Search;

use RuntimeException;

/**
 * A release was asked for that is not imported. The message names it.
 */
class UnknownReleaseException extends RuntimeException
{
    public static function for(string $version): self
    {
        return new self("Release {$version} is not imported.");
    }
}
