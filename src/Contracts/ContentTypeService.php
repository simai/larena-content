<?php

declare(strict_types=1);

namespace Larena\Content\Contracts;

use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\ContentFieldDefinition;
use Larena\Content\ValueObjects\ContentProjectionContract;
use Larena\Content\ValueObjects\ContentType;
use Larena\Content\ValueObjects\ContentTypeKey;
use Larena\Content\ValueObjects\ContentTypePage;
use Larena\Content\ValueObjects\ContentTypeQuery;
use Larena\Content\ValueObjects\ContentTypeVersion;

interface ContentTypeService
{
    /**
     * @param list<ContentFieldDefinition> $fields
     * @param array<string, scalar|null> $safeMetadata
     */
    public function create(
        ContentTypeKey $typeKey,
        array $fields,
        ContentProjectionContract $projectionContract,
        array $safeMetadata,
        ActorContext $actor,
    ): ContentType;

    public function read(ContentTypeKey $typeKey, ActorContext $actor): ContentType;

    public function version(
        ContentTypeKey $typeKey,
        int $version,
        ActorContext $actor,
    ): ContentTypeVersion;

    public function list(ContentTypeQuery $query, ActorContext $actor): ContentTypePage;
}
