<?php

declare(strict_types=1);

namespace Larena\Content\SitePack;

use JsonException;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\Runtime\ContentCanonicalJson;
use Larena\Filesystem\ValueObjects\PortableLogicalFileDescriptor;

final readonly class CmsSitePackCodec
{
    private const string ENTITIES_PATH = 'artifacts/entities/larena-cms.ndjson';
    private const string ASSETS_PATH = 'artifacts/assets/index.ndjson';

    public function __construct(private ContentCanonicalJson $json)
    {
    }

    /**
     * @param list<array<string, mixed>> $entities
     * @param list<array<string, mixed>> $assets
     * @param array<string, string> $blobs
     * @return array<string, string>
     */
    public function build(array $entities, array $assets, array $blobs, string $createdAt): array
    {
        usort($entities, static fn (array $a, array $b): int => (string) ($a['id'] ?? '') <=> (string) ($b['id'] ?? ''));
        usort($assets, static fn (array $a, array $b): int => (string) ($a['id'] ?? '') <=> (string) ($b['id'] ?? ''));
        ksort($blobs, SORT_STRING);
        $entityBytes = $this->ndjson($entities);
        $assetBytes = $this->ndjson($assets);
        $artifactMaterial = hash('sha256', $entityBytes."\0".$assetBytes);
        foreach ($blobs as $path => $bytes) {
            $artifactMaterial = hash('sha256', $artifactMaterial."\0".$path."\0".hash('sha256', $bytes));
        }
        $artifacts = [
            [
                'id' => 'larena.cms.entities',
                'mediaType' => 'application/vnd.sitepack.entity-graph+ndjson',
                'path' => self::ENTITIES_PATH,
                'size' => strlen($entityBytes),
                'digest' => 'sha256:'.hash('sha256', $entityBytes),
            ],
            [
                'id' => 'larena.cms.assets',
                'mediaType' => 'application/vnd.sitepack.asset-index+ndjson',
                'path' => self::ASSETS_PATH,
                'size' => strlen($assetBytes),
                'digest' => 'sha256:'.hash('sha256', $assetBytes),
            ],
        ];
        $manifest = [
            'spec' => ['name' => 'sitepack', 'version' => '0.4.0'],
            'package' => ['id' => 'larena-cms-'.$artifactMaterial],
            'createdAt' => $createdAt,
            'profiles' => ['content-only', 'content+assets'],
            'artifacts' => array_column($artifacts, 'id'),
            'annotations' => [
                'adapter' => 'larena/content',
                'contentModelVersion' => 1,
                'profileVersion' => 1,
            ],
        ];
        $entries = [
            'sitepack.manifest.json' => $this->json->encode($manifest)."\n",
            'sitepack.catalog.json' => $this->json->encode(['artifacts' => $artifacts])."\n",
            self::ENTITIES_PATH => $entityBytes,
            self::ASSETS_PATH => $assetBytes,
        ];
        foreach ($blobs as $path => $bytes) {
            $this->assertBlobPath($path);
            $entries[$path] = $bytes;
        }
        ksort($entries, SORT_STRING);

        return $entries;
    }

    /**
     * @param array<string, string> $entries
     * @return array{entities:list<array<string,mixed>>,assets:list<array<string,mixed>>,blobs:array<string,string>,package_id:string}
     */
    public function parse(array $entries): array
    {
        foreach (['sitepack.manifest.json', 'sitepack.catalog.json', self::ENTITIES_PATH, self::ASSETS_PATH] as $required) {
            if (!isset($entries[$required])) {
                throw new ContentRejected('sitepack_required_entry_missing');
            }
        }
        $manifest = $this->object($entries['sitepack.manifest.json'], 'sitepack_manifest_invalid');
        $catalog = $this->object($entries['sitepack.catalog.json'], 'sitepack_catalog_invalid');
        if (
            ($manifest['spec'] ?? null) !== ['name' => 'sitepack', 'version' => '0.4.0']
            || ($manifest['profiles'] ?? null) !== ['content-only', 'content+assets']
            || ($manifest['artifacts'] ?? null) !== ['larena.cms.entities', 'larena.cms.assets']
            || ($manifest['annotations'] ?? null) !== [
                'adapter' => 'larena/content',
                'contentModelVersion' => 1,
                'profileVersion' => 1,
            ]
            || !is_array($manifest['package'] ?? null)
            || !is_string($manifest['package']['id'] ?? null)
            || preg_match('/\Alarena-cms-[a-f0-9]{64}\z/D', $manifest['package']['id']) !== 1
            || !is_string($manifest['createdAt'] ?? null)
            || strtotime($manifest['createdAt']) === false
        ) {
            throw new ContentRejected('sitepack_manifest_incompatible');
        }
        $expectedCatalog = [
            ['larena.cms.entities', 'application/vnd.sitepack.entity-graph+ndjson', self::ENTITIES_PATH],
            ['larena.cms.assets', 'application/vnd.sitepack.asset-index+ndjson', self::ASSETS_PATH],
        ];
        $catalogArtifacts = $catalog['artifacts'] ?? null;
        if (!is_array($catalogArtifacts) || !array_is_list($catalogArtifacts) || count($catalogArtifacts) !== 2) {
            throw new ContentRejected('sitepack_catalog_invalid');
        }
        foreach ($expectedCatalog as $index => [$id, $mediaType, $path]) {
            $artifact = $catalogArtifacts[$index] ?? null;
            if (
                !is_array($artifact)
                || ($artifact['id'] ?? null) !== $id
                || ($artifact['mediaType'] ?? null) !== $mediaType
                || ($artifact['path'] ?? null) !== $path
                || ($artifact['size'] ?? null) !== strlen($entries[$path])
                || ($artifact['digest'] ?? null) !== 'sha256:'.hash('sha256', $entries[$path])
            ) {
                throw new ContentRejected('sitepack_artifact_integrity_failed');
            }
        }

        $entities = $this->ndjsonObjects($entries[self::ENTITIES_PATH], 'sitepack_entity_graph_invalid');
        $assets = $this->ndjsonObjects($entries[self::ASSETS_PATH], 'sitepack_asset_index_invalid');
        $this->assertUniqueIds($entities, 'sitepack_entity_identity_duplicate');
        $this->assertUniqueIds($assets, 'sitepack_asset_identity_duplicate');
        $blobs = [];
        foreach ($assets as $asset) {
            $descriptor = $this->assetDescriptor($asset);
            $path = $asset['path'] ?? null;
            if (!is_string($path)) {
                throw new ContentRejected('sitepack_asset_index_invalid');
            }
            $this->assertBlobPath($path);
            $bytes = $entries[$path] ?? null;
            if (!is_string($bytes) || strlen($bytes) !== $descriptor->sizeBytes || hash('sha256', $bytes) !== $descriptor->sha256) {
                throw new ContentRejected('sitepack_asset_integrity_failed');
            }
            $blobs[$path] = $bytes;
        }
        $allowed = array_fill_keys([
            'sitepack.manifest.json',
            'sitepack.catalog.json',
            self::ENTITIES_PATH,
            self::ASSETS_PATH,
            ...array_keys($blobs),
        ], true);
        foreach (array_keys($entries) as $path) {
            if (!isset($allowed[$path])) {
                throw new ContentRejected('sitepack_unexpected_entry');
            }
        }

        $material = hash('sha256', $entries[self::ENTITIES_PATH]."\0".$entries[self::ASSETS_PATH]);
        ksort($blobs, SORT_STRING);
        foreach ($blobs as $path => $bytes) {
            $material = hash('sha256', $material."\0".$path."\0".hash('sha256', $bytes));
        }
        if (!hash_equals('larena-cms-'.$material, $manifest['package']['id'])) {
            throw new ContentRejected('sitepack_package_identity_mismatch');
        }

        return [
            'entities' => $entities,
            'assets' => $assets,
            'blobs' => $blobs,
            'package_id' => $manifest['package']['id'],
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function ndjson(array $rows): string
    {
        return $rows === [] ? '' : implode("\n", array_map($this->json->encode(...), $rows))."\n";
    }

    /** @return array<string, mixed> */
    private function object(string $json, string $reason): array
    {
        try {
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ContentRejected($reason, 'SitePack JSON is invalid.', $exception);
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new ContentRejected($reason);
        }

        return $value;
    }

    /** @return list<array<string, mixed>> */
    private function ndjsonObjects(string $bytes, string $reason): array
    {
        if (strlen($bytes) > 134_217_728) {
            throw new ContentRejected($reason);
        }
        $rows = [];
        foreach (explode("\n", rtrim($bytes, "\n")) as $line) {
            if ($line === '') {
                continue;
            }
            if (strlen($line) > 1_048_576) {
                throw new ContentRejected($reason);
            }
            $rows[] = $this->object($line, $reason);
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $rows */
    private function assertUniqueIds(array $rows, string $reason): void
    {
        $seen = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (!is_string($id) || $id === '' || strlen($id) > 191 || isset($seen[$id])) {
                throw new ContentRejected($reason);
            }
            $seen[$id] = true;
        }
    }

    /** @param array<string, mixed> $asset */
    public function assetDescriptor(array $asset): PortableLogicalFileDescriptor
    {
        try {
            if (($asset['id'] ?? null) !== 'asset:'.($asset['logicalRef'] ?? '')) {
                throw new \InvalidArgumentException('asset_identity_mismatch');
            }

            return new PortableLogicalFileDescriptor(
                logicalRef: $this->string($asset, 'logicalRef'),
                publicId: $this->string($asset, 'publicId'),
                displayName: $this->string($asset, 'displayName'),
                originalName: $this->string($asset, 'originalName'),
                mimeType: $this->string($asset, 'mime'),
                extension: $this->string($asset, 'extension'),
                sizeBytes: $this->integer($asset, 'size'),
                sha256: $this->string($asset, 'sha256'),
                visibility: $this->string($asset, 'visibility'),
                altText: isset($asset['altText']) ? $this->string($asset, 'altText') : null,
            );
        } catch (\InvalidArgumentException $exception) {
            throw new ContentRejected('sitepack_asset_index_invalid', 'SitePack asset metadata is invalid.', $exception);
        }
    }

    private function assertBlobPath(string $path): void
    {
        if (preg_match('/\Aartifacts\/assets\/blobs\/sha256\/[a-f0-9]{64}\z/D', $path) !== 1) {
            throw new ContentRejected('sitepack_asset_path_invalid');
        }
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        if (!isset($row[$key]) || !is_string($row[$key])) {
            throw new \InvalidArgumentException('sitepack_scalar_invalid');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        if (!isset($row[$key]) || !is_int($row[$key])) {
            throw new \InvalidArgumentException('sitepack_scalar_invalid');
        }

        return $row[$key];
    }
}
