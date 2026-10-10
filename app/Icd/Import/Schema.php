<?php

namespace App\Icd\Import;

/**
 * The columns detected in a release file's header.
 */
final readonly class Schema
{
    /**
     * @param  array<string, int>  $indexes  canonical column => field position
     * @param  array<string, string>  $headerNames  canonical column => header name as written
     * @param  list<string>  $ignored  known columns that are not read
     * @param  list<string>  $unknown  columns the detector does not know
     */
    public function __construct(
        public array $indexes,
        public array $headerNames,
        public array $ignored,
        public array $unknown,
        public int $fieldCount,
        public string $versionStamp,
        public string $version,
    ) {}

    public function has(string $column): bool
    {
        return isset($this->indexes[$column]);
    }

    /** @return array{columns: array<string, string>, ignored: list<string>, unknown: list<string>, version_stamp: string} */
    public function columnMap(): array
    {
        return [
            'columns' => $this->headerNames,
            'ignored' => $this->ignored,
            'unknown' => $this->unknown,
            'version_stamp' => $this->versionStamp,
        ];
    }
}
