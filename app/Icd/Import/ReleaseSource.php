<?php

namespace App\Icd\Import;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;
use ZipArchive;

/**
 * Finds the release file for a version: a --file path, the cached download
 * in {storage_path}/{version}/release.zip, or a new download from WHO.
 * Only files downloaded in this run are ever deleted.
 */
class ReleaseSource
{
    private const ZIP_SIGNATURE = "PK\x03\x04";

    private const DOWNLOAD_ATTEMPTS = 3;

    private const DOWNLOAD_TIMEOUT_SECONDS = 60;

    public static function validateVersion(string $version): void
    {
        if (! preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $version)) {
            throw new ImportException("Invalid version \"{$version}\": expected YYYY-MM with a month from 01 to 12, e.g. 2026-01.");
        }
    }

    public function resolve(string $version, ?string $file, bool $fresh): ResolvedRelease
    {
        self::validateVersion($version);

        if ($file !== null) {
            return $this->local($file);
        }

        $path = config('icd.storage_path')."/{$version}/release.zip";

        if (! $fresh && is_file($path)) {
            self::checkZip($path, "The cached file {$path}", ' Remove it or rerun with --fresh.');

            return new ResolvedRelease($path, isZip: true, cached: true, sourceUrl: $path);
        }

        return $this->download($version, $path);
    }

    private function local(string $file): ResolvedRelease
    {
        $path = str_starts_with($file, '/') ? $file : base_path($file);

        if (! is_file($path)) {
            throw new ImportException("The file {$file} does not exist. Paths are inside the container; place release files under the project folder.");
        }

        $isZip = str_ends_with(strtolower($path), '.zip');

        if ($isZip) {
            self::checkZip($path, "The file {$path}");
        }

        return new ResolvedRelease($path, $isZip, cached: false, sourceUrl: $path);
    }

    private function download(string $version, string $path): ResolvedRelease
    {
        $url = str_replace('{version}', $version, config('icd.release_url'));
        $partial = "{$path}.download";
        $kept = false;
        File::ensureDirectoryExists(dirname($path));

        try {
            $response = Http::timeout(self::DOWNLOAD_TIMEOUT_SECONDS)
                ->retry(
                    self::DOWNLOAD_ATTEMPTS,
                    config('icd.download.retry_delay_ms'),
                    fn (Throwable $e) => $e instanceof ConnectionException
                        || ($e instanceof RequestException && $e->response->serverError()),
                    throw: false,
                )
                ->sink($partial)
                ->get($url);

            if ($response->status() === 404) {
                throw new ImportException("Release {$version} was not found: {$url} returned HTTP 404.");
            }

            if ($response->failed()) {
                throw new ImportException("Cannot download {$url}: HTTP {$response->status()} after ".self::DOWNLOAD_ATTEMPTS.' attempts.');
            }

            $label = "The download from {$url} to {$path}";
            self::checkZip($partial, $label, ' It was deleted.');

            try {
                ReleaseReader::checkDataEntry($partial, $label);
            } catch (ImportException $e) {
                throw new ImportException("{$e->getMessage()} It was deleted.");
            }

            rename($partial, $path);
            $kept = true;
        } catch (ConnectionException $e) {
            throw new ImportException("Cannot download {$url} after ".self::DOWNLOAD_ATTEMPTS." attempts: {$e->getMessage()}");
        } finally {
            if (! $kept) {
                $this->discard($partial);
            }
        }

        return new ResolvedRelease($path, isZip: true, cached: false, sourceUrl: $url);
    }

    /** Fails unless the file starts with the zip signature and ZipArchive can open it. */
    private static function checkZip(string $path, string $label, string $advice = ''): void
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new ImportException("{$label} cannot be read.{$advice}");
        }

        $signature = fread($handle, strlen(self::ZIP_SIGNATURE));
        fclose($handle);

        if ($signature !== self::ZIP_SIGNATURE) {
            throw new ImportException("{$label} is not a zip file.{$advice}");
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ImportException("{$label} cannot be opened as a zip file; it may be truncated.{$advice}");
        }
        $zip->close();
    }

    /** Deletes a file downloaded in this run, and its version folder if that is now empty. */
    private function discard(string $partial): void
    {
        File::delete($partial);

        if (File::isEmptyDirectory($dir = dirname($partial))) {
            File::deleteDirectory($dir);
        }
    }
}
