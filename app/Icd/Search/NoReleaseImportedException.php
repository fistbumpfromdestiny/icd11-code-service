<?php

namespace App\Icd\Search;

use RuntimeException;

/**
 * Nothing can be searched because no release has been imported yet.
 */
class NoReleaseImportedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No ICD release imported');
    }
}
