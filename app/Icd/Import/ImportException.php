<?php

namespace App\Icd\Import;

use RuntimeException;

/**
 * A release file that cannot be imported. The message is shown to the user.
 */
class ImportException extends RuntimeException {}
