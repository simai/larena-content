<?php

declare(strict_types=1);

namespace Larena\Content\Contracts;

use Larena\Content\ValueObjects\ContentLogicalFileInspection;

/**
 * Read-only port to larena/filesystem.
 *
 * Content never uploads, mutates, deletes, or issues delivery URLs for files.
 */
interface ContentLogicalFileInspector
{
    public function inspect(string $logicalFileRef): ContentLogicalFileInspection;
}
