<?php

declare(strict_types=1);

namespace Larena\Content\Contracts;

use Larena\Dataview\Contracts\DataviewSourceProvider;

/**
 * Marker contract for Access-scoped, read-only Content presentation rows.
 *
 * The inherited Dataview API exposes descriptor() and rows() only.
 */
interface ContentDataviewSourceProvider extends DataviewSourceProvider
{
}
