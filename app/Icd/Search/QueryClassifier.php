<?php

namespace App\Icd\Search;

/**
 * Decides whether a query is a code (or code prefix) or free text, and
 * normalizes it. Free-text words contain only letters and digits, so they
 * can never carry tsquery operators.
 */
class QueryClassifier
{
    // ICD-11 code or a prefix of one: 6A, 6A7, 6A70, 6A70.0, ME84.20, FA3Z.
    private const CODE = '/^[0-9A-Z][A-Z](?:[0-9](?:[0-9A-Z](?:\.[0-9A-Z]{0,2})?)?)?$/i';

    public function classify(string $query): ClassifiedQuery
    {
        $trimmed = trim($query);

        // A digit is required, so two-letter words such as "ms" stay text.
        if (preg_match(self::CODE, $trimmed) && preg_match('/[0-9]/', $trimmed)) {
            return new ClassifiedQuery(true, strtoupper($trimmed), []);
        }

        $text = str_replace(["'", '’'], '', mb_strtolower($trimmed));
        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return new ClassifiedQuery(false, null, $words);
    }
}
