<?php

namespace App\Icd\Import;

/**
 * A release file ready to read: downloaded, cached or given with --file.
 */
final readonly class ResolvedRelease
{
    public function __construct(
        public string $path,
        public bool $isZip,
        public bool $cached,
        public string $sourceUrl,
    ) {}

    public function reader(): ReleaseReader
    {
        return $this->isZip ? ReleaseReader::fromZip($this->path) : ReleaseReader::fromTxt($this->path);
    }

    public function sha256(): string
    {
        return ReleaseReader::dataSha256($this->path, $this->isZip);
    }
}
