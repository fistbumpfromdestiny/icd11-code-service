<?php

namespace App\Icd\Diff;

/**
 * The kinds of change between two releases, in the order they are listed.
 */
enum ChangeType: string
{
    case Added = 'added';
    case Removed = 'removed';
    case Recoded = 'recoded';
    case Moved = 'moved';
    case CodeReused = 'code_reused';
    case Retitled = 'retitled';
    case RetitledCosmetic = 'retitled_cosmetic';
}
