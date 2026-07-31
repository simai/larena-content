<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Contract;

use Larena\Content\Contracts\StarterSiteInitializer;
use Larena\Content\FirstRun\ContentFirstRunContributor;
use Larena\Core\Contracts\FirstRunContributor;
use Larena\Core\FirstRun\FirstRunContext;
use Larena\Core\FirstRun\FirstRunPayload;
use PHPUnit\Framework\TestCase;

final class ContentFirstRunContributorContractTest extends TestCase
{
    public function test_it_uses_the_content_owned_initializer_and_returns_only_the_stable_item_ref(): void
    {
        $starter = new class implements StarterSiteInitializer {
            /** @var list<string> */
            public array $received = [];
            public function firstRunState(): string { return FirstRunContributor::STATE_EMPTY; }
            public function initialize(string $subjectRef, string $siteName, string $locale): string
            {
                $this->received = [$subjectRef, $siteName, $locale];
                return 'content:item:starter';
            }
        };
        $contributor = new ContentFirstRunContributor($starter);
        $payload = new FirstRunPayload('Owner', 'owner@example.test', 'Strong-password!', 'Starter site', 'en', 'UTC');
        $context = $contributor->apply($payload, (new FirstRunContext())->with('auth.subject_ref', 'user:admin_identity:1'));

        self::assertSame('content', $contributor->id());
        self::assertSame([], $contributor->validate($payload));
        self::assertSame('content:item:starter', $context->string('content.homepage_item_ref'));
        self::assertSame(['user:admin_identity:1', 'Starter site', 'en'], $starter->received);
    }
}
