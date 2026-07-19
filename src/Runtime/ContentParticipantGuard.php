<?php

declare(strict_types=1);

namespace Larena\Content\Runtime;

use Illuminate\Database\ConnectionInterface;
use Larena\Audit\Contracts\ConnectionBoundAuditEventPipeline;
use Larena\Content\Exceptions\ContentIntegrationFailed;
use Larena\Search\Persistence\DatabaseSearchIndex;
use Larena\Storage\Contracts\VersionedStorage;
use Throwable;

final readonly class ContentParticipantGuard
{
    public function __construct(
        private ConnectionInterface $content,
        private VersionedStorage $storage,
        private DatabaseSearchIndex $search,
        private ConnectionBoundAuditEventPipeline $audit,
    ) {
    }

    /**
     * Resolves and compares participants only. No SQL may occur here.
     */
    public function assertSharedConnection(): ConnectionInterface
    {
        try {
            $storage = $this->storage->connection();
            $search = $this->search->connection();
            $audit = $this->audit->connection();
        } catch (Throwable $exception) {
            throw new ContentIntegrationFailed(
                'connection',
                'participant_resolution_failed',
                $exception,
            );
        }

        if (
            $this->content !== $storage
            || $this->content !== $search
            || $this->content !== $audit
        ) {
            throw new ContentIntegrationFailed(
                'connection',
                'participant_connection_mismatch',
            );
        }

        return $this->content;
    }
}
