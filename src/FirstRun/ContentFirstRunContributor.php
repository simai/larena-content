<?php

declare(strict_types=1);

namespace Larena\Content\FirstRun;

use Larena\Content\Contracts\StarterSiteInitializer;
use Larena\Core\Contracts\FirstRunContributor;
use Larena\Core\FirstRun\FirstRunContext;
use Larena\Core\FirstRun\FirstRunPayload;

final readonly class ContentFirstRunContributor implements FirstRunContributor
{
    public function __construct(private StarterSiteInitializer $starter)
    {
    }

    public function id(): string { return 'content'; }
    public function priority(): int { return 300; }
    public function validate(FirstRunPayload $payload): array { return []; }
    public function state(): string { return $this->starter->firstRunState(); }

    public function apply(FirstRunPayload $payload, FirstRunContext $context): FirstRunContext
    {
        $itemRef = $this->starter->initialize(
            $context->string('auth.subject_ref'),
            $payload->siteName,
            $payload->locale,
        );

        return $context->with('content.homepage_item_ref', $itemRef);
    }
}
