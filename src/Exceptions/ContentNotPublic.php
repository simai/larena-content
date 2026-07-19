<?php

declare(strict_types=1);

namespace Larena\Content\Exceptions;

final class ContentNotPublic extends ContentRejected
{
    public function __construct()
    {
        parent::__construct(
            'content_not_public',
            'The requested Content projection is not public.',
        );
    }
}
