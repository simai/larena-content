<?php

declare(strict_types=1);

namespace Larena\Content\Persistence;

use Illuminate\Database\Connection;
use Larena\Content\Database\SiteStructureTableShapeGuard;

final readonly class DatabaseSiteStructureRepository
{
    public function __construct(private Connection $database)
    {
    }

    public function connection(): Connection
    {
        return $this->database;
    }

    public function assertCompleteCompatible(): void
    {
        (new SiteStructureTableShapeGuard($this->database))->assertCompleteCompatible();
    }

    /** @return array<string, mixed>|null */
    public function head(bool $forUpdate = false): ?array
    {
        $query = $this->database->table('larena_content_site_structures')->where('structure_ref', 'primary');
        $row = ($forUpdate ? $query->lockForUpdate() : $query)->first();

        return $row === null ? null : (array) $row;
    }

    /** @return array<string, mixed>|null */
    public function revision(int $revision, bool $forUpdate = false): ?array
    {
        $query = $this->database->table('larena_content_site_structure_revisions')
            ->where('structure_ref', 'primary')->where('revision', $revision);
        $row = ($forUpdate ? $query->lockForUpdate() : $query)->first();

        return $row === null ? null : (array) $row;
    }

    /** @return list<array<string, mixed>> */
    public function revisions(): array
    {
        return array_values(array_map(
            static fn (object $row): array => (array) $row,
            $this->database->table('larena_content_site_structure_revisions')
                ->where('structure_ref', 'primary')->orderBy('revision')->get()->all(),
        ));
    }

    /**
     * @param array<string, mixed> $head
     * @param array<string, mixed> $revision
     */
    public function create(array $head, array $revision): void
    {
        $this->database->table('larena_content_site_structure_revisions')->insert($revision);
        $this->database->table('larena_content_site_structures')->insert($head);
    }

    /** @param array<string, mixed> $revision */
    public function append(int $expectedRevision, array $revision, string $status, ?int $publishedRevision, string $updatedAt): bool
    {
        $this->database->table('larena_content_site_structure_revisions')->insert($revision);

        return $this->database->table('larena_content_site_structures')
            ->where('structure_ref', 'primary')->where('current_revision', $expectedRevision)
            ->update([
                'current_revision' => (int) $revision['revision'],
                'current_status' => $status,
                'published_revision' => $publishedRevision,
                'updated_at' => $updatedAt,
            ]) === 1;
    }

    /** @return array<string, mixed>|null */
    public function redirect(string $typeKey, string $locale, string $slug, bool $forUpdate = false): ?array
    {
        $query = $this->database->table('larena_content_redirects')
            ->where('type_key', $typeKey)->where('locale', $locale)->where('source_slug', $slug);
        $row = ($forUpdate ? $query->lockForUpdate() : $query)->first();

        return $row === null ? null : (array) $row;
    }

    /** @return list<array<string, mixed>> */
    public function redirects(): array
    {
        return array_values(array_map(
            static fn (object $row): array => (array) $row,
            $this->database->table('larena_content_redirects')
                ->orderBy('type_key')->orderBy('locale')->orderBy('source_slug')->get()->all(),
        ));
    }

    public function reserveRedirect(string $typeKey, string $locale, string $sourceSlug, string $itemRef, string $timestamp): void
    {
        $existing = $this->redirect($typeKey, $locale, $sourceSlug, true);
        if ($existing !== null) {
            if ((string) $existing['item_ref'] !== $itemRef) {
                throw new \Larena\Content\Exceptions\ContentRejected('redirect_source_conflict');
            }

            return;
        }
        $this->database->table('larena_content_redirects')->insert([
            'type_key' => $typeKey,
            'locale' => $locale,
            'source_slug' => $sourceSlug,
            'item_ref' => $itemRef,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    /**
     * @param array<string, mixed> $head
     * @param list<array<string, mixed>> $revisions
     */
    public function insertImportedStructure(array $head, array $revisions): void
    {
        if ($revisions !== []) {
            $this->database->table('larena_content_site_structure_revisions')->insert($revisions);
        }
        $this->database->table('larena_content_site_structures')->insert($head);
    }

    /** @param array<string, mixed> $redirect */
    public function insertImportedRedirect(array $redirect): void
    {
        $this->database->table('larena_content_redirects')->insert($redirect);
    }
}
