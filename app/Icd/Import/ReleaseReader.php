<?php

namespace App\Icd\Import;

use Generator;
use ZipArchive;

/**
 * Reads a WHO SimpleTabulation file as quoted TSV records.
 *
 * Records end in CRLF, but CodingNote holds unquoted bare LF line breaks, so
 * the file is split on every LF and lines are joined until a record has as
 * many fields as the header. Line numbers count every LF; the header is
 * line 1.
 */
class ReleaseReader
{
    private const BOM = "\u{FEFF}";

    /** @var resource */
    private $stream;

    private ?array $header = null;

    private int $line = 0;

    /** @param resource $stream */
    private function __construct($stream, private ?ZipArchive $zip = null)
    {
        $this->stream = $stream;
    }

    public static function fromTxt(string $path): self
    {
        $stream = @fopen($path, 'rb');

        if ($stream === false) {
            throw new ImportException("Cannot open {$path}.");
        }

        return new self($stream);
    }

    /**
     * Reads the data .txt inside the zip without extracting it: the only
     * entry ending in .txt, readme.txt excluded.
     */
    public static function fromZip(string $path): self
    {
        [$zip, $stream] = self::openDataEntry($path);

        return new self($stream, $zip);
    }

    /** The sha256 of the data .txt, read from the zip without extracting it. */
    public static function dataSha256(string $path, bool $isZip): string
    {
        if (! $isZip) {
            return hash_file('sha256', $path);
        }

        [$zip, $stream] = self::openDataEntry($path);
        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);
        $zip->close();

        return hash_final($context);
    }

    /**
     * Fails unless the zip holds exactly one data .txt. $label names the zip
     * in the message; it defaults to the path.
     */
    public static function checkDataEntry(string $path, ?string $label = null): void
    {
        $zip = self::openZip($path);
        self::dataEntry($zip, $label ?? $path);
        $zip->close();
    }

    /** @return array{ZipArchive, resource} */
    private static function openDataEntry(string $path): array
    {
        $zip = self::openZip($path);
        $entry = self::dataEntry($zip, $path);
        $stream = $zip->getStream($entry);

        if ($stream === false) {
            throw new ImportException("Cannot read {$entry} in {$path}.");
        }

        return [$zip, $stream];
    }

    private static function openZip(string $path): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ImportException("Cannot open {$path} as a zip file.");
        }

        return $zip;
    }

    /** The only entry ending in .txt, readme.txt excluded. */
    private static function dataEntry(ZipArchive $zip, string $label): string
    {
        $candidates = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $base = strtolower(basename($name));
            if (str_ends_with($base, '.txt') && $base !== 'readme.txt') {
                $candidates[] = $name;
            }
        }

        if ($candidates === []) {
            throw new ImportException("{$label} contains no data .txt file.");
        }

        if (count($candidates) > 1) {
            throw new ImportException("{$label} contains several candidate data files: ".implode(', ', $candidates).'.');
        }

        return $candidates[0];
    }

    /** @return list<string> */
    public function header(): array
    {
        if ($this->header !== null) {
            return $this->header;
        }

        $line = fgets($this->stream);

        if ($line === false) {
            throw new ImportException('The release file is empty.');
        }

        $this->line = 1;

        if (str_starts_with($line, self::BOM)) {
            $line = substr($line, strlen(self::BOM));
        }

        return $this->header = self::parse(rtrim($line, "\r\n"));
    }

    /**
     * Yields each record's fields, keyed by the line where the record starts.
     *
     * @return Generator<int, list<string>>
     */
    public function records(int $fieldCount): Generator
    {
        $this->header();

        $buffer = '';
        $start = 0;
        $shortFields = null;

        while (($line = fgets($this->stream)) !== false) {
            // CRLF ends a record; only bare LF occurs inside one. A record still
            // short at a CRLF is malformed, unless the file ends there (below).
            if ($shortFields !== null) {
                throw new ImportException(sprintf(
                    'The record starting on line %d ends on line %d with %d fields, expected %d.',
                    $start, $this->line, $shortFields, $fieldCount,
                ));
            }

            $this->line++;

            if ($buffer === '') {
                $start = $this->line;
            }

            $buffer .= $line;
            $fields = self::parse(self::stripTerminator($buffer));

            if (count($fields) < $fieldCount) {
                if (str_ends_with($line, "\r\n")) {
                    $shortFields = count($fields);
                }

                continue;
            }

            if (count($fields) > $fieldCount) {
                throw new ImportException(sprintf(
                    'The record starting on line %d has %d fields, expected %d.',
                    $start, count($fields), $fieldCount,
                ));
            }

            yield $start => $fields;
            $buffer = '';
        }

        // The last record ends in CRLF, so only a non-empty remainder is an error.
        if (self::stripTerminator($buffer) !== '') {
            throw new ImportException("The file ends inside the record starting on line {$start}.");
        }
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->zip?->close();
    }

    private static function stripTerminator(string $text): string
    {
        if (str_ends_with($text, "\r\n")) {
            return substr($text, 0, -2);
        }

        return str_ends_with($text, "\n") ? substr($text, 0, -1) : $text;
    }

    /** @return list<string> */
    private static function parse(string $record): array
    {
        return array_map(fn ($field) => $field ?? '', str_getcsv($record, "\t", '"', ''));
    }
}
