<?php

namespace App\Icd\Search;

/**
 * A search query after classification: a code or code prefix (upper-cased),
 * or free text as lower-case words of letters and digits only.
 */
final readonly class ClassifiedQuery
{
    /** @param list<string> $words */
    public function __construct(
        public bool $isCode,
        public ?string $code,
        public array $words,
    ) {}
}
