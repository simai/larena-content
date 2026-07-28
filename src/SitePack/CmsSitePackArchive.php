<?php

declare(strict_types=1);

namespace Larena\Content\SitePack;

use Larena\Content\Exceptions\ContentRejected;
use RuntimeException;
use Throwable;
use ZipArchive;

final readonly class CmsSitePackArchive
{
    private const int DETERMINISTIC_MTIME = 315532800;

    public function __construct(
        private string $root,
        private int $maximumBytes = 268_435_456,
        private int $maximumEntries = 5000,
    ) {
        if ($root === '' || $maximumBytes < 1 || $maximumEntries < 3) {
            throw new \InvalidArgumentException('content_sitepack_archive_config_invalid');
        }
    }

    /**
     * @param array<string, string> $entries
     * @return array{package_ref:string,digest:string}
     */
    public function store(array $entries): array
    {
        if ($entries === [] || !isset($entries['sitepack.manifest.json'], $entries['sitepack.catalog.json'])) {
            throw new ContentRejected('sitepack_required_entry_missing');
        }
        ksort($entries, SORT_STRING);
        $this->ensureRoot();
        $temporary = tempnam($this->root, '.sitepack-');
        if (!is_string($temporary)) {
            throw new RuntimeException('content_sitepack_temporary_file_failed');
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('content_sitepack_archive_open_failed');
            }
            foreach ($entries as $path => $contents) {
                $this->assertEntryPath($path);
                if (!$zip->addFromString($path, $contents)) {
                    throw new RuntimeException('content_sitepack_archive_write_failed');
                }
                $zip->setMtimeName($path, self::DETERMINISTIC_MTIME);
            }
            if (!$zip->close()) {
                throw new RuntimeException('content_sitepack_archive_close_failed');
            }
            $size = filesize($temporary);
            if (!is_int($size) || $size < 1 || $size > $this->maximumBytes) {
                throw new ContentRejected('sitepack_archive_size_invalid');
            }
            $digest = hash_file('sha256', $temporary);
            if (!is_string($digest)) {
                throw new RuntimeException('content_sitepack_digest_failed');
            }
            $packageRef = 'cms-'.$digest.'.sitepack';
            $destination = $this->path($packageRef);
            if (!is_file($destination) && !rename($temporary, $destination)) {
                throw new RuntimeException('content_sitepack_archive_publish_failed');
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }

            return ['package_ref' => $packageRef, 'digest' => $digest];
        } catch (Throwable $exception) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            throw $exception;
        }
    }

    /**
     * @return array{digest:string,entries:array<string,string>}
     */
    public function read(string $packageRef): array
    {
        if (preg_match('/\Acms-([a-f0-9]{64})\.sitepack\z/D', $packageRef, $matches) !== 1) {
            throw new ContentRejected('sitepack_package_ref_invalid');
        }
        $path = $this->path($packageRef);
        $size = is_file($path) ? filesize($path) : false;
        if (!is_int($size) || $size < 1 || $size > $this->maximumBytes) {
            throw new ContentRejected('sitepack_package_unavailable');
        }
        $digest = hash_file('sha256', $path);
        if (!is_string($digest) || !hash_equals($matches[1], $digest)) {
            throw new ContentRejected('sitepack_package_integrity_failed');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ContentRejected('sitepack_archive_invalid');
        }
        try {
            if ($zip->numFiles < 3 || $zip->numFiles > $this->maximumEntries) {
                throw new ContentRejected('sitepack_archive_entry_count_invalid');
            }
            $entries = [];
            $uncompressed = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
                if ($stat === false) {
                    throw new ContentRejected('sitepack_archive_entry_invalid');
                }
                if ($stat['encryption_method'] !== 0) {
                    throw new ContentRejected('sitepack_archive_entry_encrypted');
                }
                $operatingSystem = 0;
                $externalAttributes = 0;
                if (
                    $zip->getExternalAttributesIndex(
                        $index,
                        $operatingSystem,
                        $externalAttributes,
                        ZipArchive::FL_UNCHANGED,
                    )
                    && (($externalAttributes >> 16) & 0170000) === 0120000
                ) {
                    throw new ContentRejected('sitepack_archive_entry_symlink');
                }
                $name = $stat['name'];
                $this->assertEntryPath($name);
                if (isset($entries[$name])) {
                    throw new ContentRejected('sitepack_archive_entry_duplicate');
                }
                $uncompressed += (int) $stat['size'];
                if ($uncompressed > $this->maximumBytes) {
                    throw new ContentRejected('sitepack_archive_expansion_limit_exceeded');
                }
                $contents = $zip->getFromIndex($index, 0, ZipArchive::FL_UNCHANGED);
                if (!is_string($contents) || strlen($contents) !== (int) $stat['size']) {
                    throw new ContentRejected('sitepack_archive_entry_unreadable');
                }
                $entries[$name] = $contents;
            }
        } finally {
            $zip->close();
        }

        return ['digest' => $digest, 'entries' => $entries];
    }

    private function ensureRoot(): void
    {
        if (!is_dir($this->root) && !mkdir($this->root, 0700, true) && !is_dir($this->root)) {
            throw new RuntimeException('content_sitepack_root_create_failed');
        }
        if (!is_writable($this->root)) {
            throw new RuntimeException('content_sitepack_root_not_writable');
        }
    }

    private function path(string $packageRef): string
    {
        return rtrim($this->root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$packageRef;
    }

    private function assertEntryPath(string $path): void
    {
        if (
            $path === ''
            || strlen($path) > 240
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || preg_match('/(?:\A|\/)\.\.(?:\/|\z)|[\x00-\x1F\x7F]/', $path) === 1
            || str_ends_with($path, '/')
        ) {
            throw new ContentRejected('sitepack_archive_entry_path_invalid');
        }
    }
}
