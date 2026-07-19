<?php

declare(strict_types=1);

namespace Larena\Content\Filesystem;

use Larena\Content\Contracts\ContentLogicalFileInspector;
use Larena\Content\Exceptions\ContentIntegrationFailed;
use Larena\Content\Runtime\ContentInputGuard;
use Larena\Content\ValueObjects\ContentLogicalFileInspection;
use Larena\Filesystem\Contracts\PersistentLogicalFileInspector;
use Throwable;

final readonly class FilesystemContentLogicalFileInspector implements ContentLogicalFileInspector
{
    public function __construct(
        private PersistentLogicalFileInspector $filesystem,
        private ContentInputGuard $input,
    ) {
    }

    public function inspect(string $logicalFileRef): ContentLogicalFileInspection
    {
        $this->input->assertLogicalFileRef($logicalFileRef);

        try {
            $inspection = $this->filesystem->inspect($logicalFileRef);

            if ($inspection->logicalRef !== $logicalFileRef) {
                throw new \UnexpectedValueException('Filesystem returned an inspection for another logical file.');
            }

            if (!$inspection->exists) {
                if ($inspection->safeMetadata !== []) {
                    throw new \UnexpectedValueException('A missing logical file exposed metadata.');
                }

                return new ContentLogicalFileInspection(
                    logicalFileRef: $logicalFileRef,
                    exists: false,
                    available: false,
                    public: false,
                    safeMetadata: [],
                );
            }

            $metadata = $inspection->safeMetadata;
            if ($metadata === []) {
                throw new \UnexpectedValueException('Filesystem metadata did not match the exact safe allowlist.');
            }

            if ($metadata['alt_text'] === '') {
                $metadata['alt_text'] = null;
            }

            return ContentLogicalFileInspection::fromFilesystemInspection($inspection);
        } catch (ContentIntegrationFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ContentIntegrationFailed(
                'filesystem',
                'logical_file_inspection_failed',
                $exception,
            );
        }
    }
}
