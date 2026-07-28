<?php

declare(strict_types=1);

namespace Larena\Content\Access;

use Larena\Access\ValueObjects\AccessOperationDescriptor;

final class ContentAccessOperationCatalog
{
    /**
     * @var list<array{
     *     code: string,
     *     label: string,
     *     target: string,
     *     grant: string,
     *     risk: string
     * }>
     */
    private const DEFINITIONS = [
        ['code' => 'content.type.list', 'label' => 'type_list', 'target' => 'content.type:all', 'grant' => 'list', 'risk' => 'high'],
        ['code' => 'content.type.read', 'label' => 'type_read', 'target' => 'content.type:all', 'grant' => 'read', 'risk' => 'high'],
        ['code' => 'content.type.create', 'label' => 'type_create', 'target' => 'content.type:all', 'grant' => 'create', 'risk' => 'critical'],
        ['code' => 'content.type.version.preview', 'label' => 'type_version_preview', 'target' => 'content.type:all', 'grant' => 'read', 'risk' => 'critical'],
        ['code' => 'content.type.version.create', 'label' => 'type_version_create', 'target' => 'content.type:all', 'grant' => 'update', 'risk' => 'critical'],
        ['code' => 'content.item.list', 'label' => 'item_list', 'target' => 'content.item:all', 'grant' => 'list', 'risk' => 'high'],
        ['code' => 'content.item.read', 'label' => 'item_read', 'target' => 'content.item:all', 'grant' => 'read', 'risk' => 'high'],
        ['code' => 'content.item.create', 'label' => 'item_create', 'target' => 'content.item:all', 'grant' => 'create', 'risk' => 'high'],
        ['code' => 'content.item.update', 'label' => 'item_update', 'target' => 'content.item:all', 'grant' => 'update', 'risk' => 'high'],
        ['code' => 'content.item.submit_review', 'label' => 'item_submit_review', 'target' => 'content.item:all', 'grant' => 'submit_review', 'risk' => 'high'],
        ['code' => 'content.item.restore', 'label' => 'item_restore', 'target' => 'content.item:all', 'grant' => 'restore', 'risk' => 'critical'],
        ['code' => 'content.revision.list', 'label' => 'revision_list', 'target' => 'content.revision:all', 'grant' => 'list', 'risk' => 'high'],
        ['code' => 'content.revision.read', 'label' => 'revision_read', 'target' => 'content.revision:all', 'grant' => 'read', 'risk' => 'high'],
        ['code' => 'content.item.publish', 'label' => 'item_publish', 'target' => 'content.item:all', 'grant' => 'publish', 'risk' => 'critical'],
        ['code' => 'content.item.unpublish', 'label' => 'item_unpublish', 'target' => 'content.item:all', 'grant' => 'publish', 'risk' => 'critical'],
        ['code' => 'content.attachment.list', 'label' => 'attachment_list', 'target' => 'content.attachment:all', 'grant' => 'list', 'risk' => 'high'],
        ['code' => 'content.attachment.attach', 'label' => 'attachment_attach', 'target' => 'content.attachment:all', 'grant' => 'attach', 'risk' => 'high'],
        ['code' => 'content.attachment.detach', 'label' => 'attachment_detach', 'target' => 'content.attachment:all', 'grant' => 'detach', 'risk' => 'high'],
        ['code' => 'content.attachment.reorder', 'label' => 'attachment_reorder', 'target' => 'content.attachment:all', 'grant' => 'reorder', 'risk' => 'high'],
        ['code' => 'content.structure.read', 'label' => 'structure_read', 'target' => 'content.structure:primary', 'grant' => 'read', 'risk' => 'high'],
        ['code' => 'content.structure.update', 'label' => 'structure_update', 'target' => 'content.structure:primary', 'grant' => 'update', 'risk' => 'high'],
        ['code' => 'content.structure.submit_review', 'label' => 'structure_submit_review', 'target' => 'content.structure:primary', 'grant' => 'submit_review', 'risk' => 'high'],
        ['code' => 'content.structure.publish', 'label' => 'structure_publish', 'target' => 'content.structure:primary', 'grant' => 'publish', 'risk' => 'critical'],
        ['code' => 'content.structure.restore', 'label' => 'structure_restore', 'target' => 'content.structure:primary', 'grant' => 'restore', 'risk' => 'critical'],
        ['code' => 'content.redirect.list', 'label' => 'redirect_list', 'target' => 'content.redirect:all', 'grant' => 'list', 'risk' => 'high'],
        ['code' => 'content.sitepack.export', 'label' => 'sitepack_export', 'target' => 'content.sitepack:all', 'grant' => 'export', 'risk' => 'critical'],
        ['code' => 'content.sitepack.verify', 'label' => 'sitepack_verify', 'target' => 'content.sitepack:all', 'grant' => 'verify', 'risk' => 'critical'],
        ['code' => 'content.sitepack.import.dry_run', 'label' => 'sitepack_import_dry_run', 'target' => 'content.sitepack:all', 'grant' => 'import', 'risk' => 'critical'],
        ['code' => 'content.sitepack.import.apply', 'label' => 'sitepack_import_apply', 'target' => 'content.sitepack:all', 'grant' => 'import', 'risk' => 'critical'],
    ];

    /**
     * @return list<AccessOperationDescriptor>
     */
    public static function operations(): array
    {
        return array_map(
            static fn (array $definition): AccessOperationDescriptor => new AccessOperationDescriptor(
                code: $definition['code'],
                ownerPackage: 'larena/content',
                labelKey: 'larena-content::operations.' . $definition['label'],
                target: $definition['target'],
                requiredGrant: $definition['grant'],
                risk: $definition['risk'],
                auditDenials: true,
            ),
            self::DEFINITIONS,
        );
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_column(self::DEFINITIONS, 'code');
    }

    public static function contains(string $operation): bool
    {
        return in_array($operation, self::codes(), true);
    }
}
