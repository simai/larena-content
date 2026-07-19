<?php

declare(strict_types=1);

namespace Larena\Content\Tests\Contract;

use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Audit\Contracts\AuditEventDescriptor;
use Larena\Content\Audit\ContentAuditEventDescriptor;
use Larena\Content\Contracts\ContentDataviewSourceProvider;
use Larena\Content\Contracts\ContentLogicalFileInspector;
use Larena\Content\Contracts\ContentSearchSourceProvider;
use Larena\Content\Tests\Fixtures\ContentPlatformV1Fixture;
use Larena\Content\Tests\TestCase;
use Larena\Content\ValueObjects\ContentLocale;
use Larena\Content\ValueObjects\ContentSearchProjection;
use Larena\Dataview\Contracts\DataviewSourceProvider;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Search\Contracts\ReindexSource;
use Larena\Search\Contracts\SearchProjection;
use Larena\Storage\Contracts\VersionedStorage;
use ReflectionClass;
use ReflectionMethod;

final class DependencyCompatibilityContractTest extends TestCase
{
    private const EXACT_REVISIONS = [
        'larena/access' => '8c0e75897fe422a8f4d97fc012f1d095ffdba3b2',
        'larena/audit' => 'ab2546b1a0fdd577faba895755a3d6c44f0f9da8',
        'larena/core' => '46f3bbc8baba0262117bc9b9519713ee21b1d981',
        'larena/dataview' => 'b84e964b4ed78e1ca08a46c88e7651b02744ee47',
        'larena/filesystem' => '6c784d0ad84e5fcc72b515c8b5b27bafac9ee31f',
        'larena/layout' => 'cb5bdadf588cb8480972279bea3888500dbf9d6e',
        'larena/licensing' => '52d1215a25369cca17d5170bbfcae82d1f6c86d2',
        'larena/property' => '92b6e915fc4c85239171dbbff6c3cb15d046cc99',
        'larena/search' => 'e7206b2491991790edd2858c993d142184c749ef',
        'larena/storage' => '7645c0124999eeab6150edc0b0b949adc17be310',
        'larena/ui' => '07fff2579344d7c77a28716a74071fb53f0bbfc9',
    ];

    public function test_lock_contains_the_exact_accepted_dependency_closure(): void
    {
        $lock = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/composer.lock'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($lock);

        $packages = array_merge($lock['packages'], $lock['packages-dev']);
        $actual = [];

        foreach ($packages as $package) {
            if (str_starts_with($package['name'], 'larena/')) {
                $actual[$package['name']] = $package['source']['reference'] ?? null;
            }
        }

        ksort($actual);
        $expected = self::EXACT_REVISIONS;
        ksort($expected);

        self::assertSame($expected, $actual);
    }

    public function test_accepted_owner_interfaces_keep_the_expected_signatures(): void
    {
        self::assertSame(
            [
                'compareAndSwap',
                'connection',
                'create',
                'projectPublicVersion',
                'readAdminCurrentVersion',
                'readAdminVersion',
                'registerSchemaVersion',
                'schemaVersion',
            ],
            $this->publicMethodNames(VersionedStorage::class),
        );
        self::assertSame(['assertAllowed'], $this->publicMethodNames(ActorOperationAuthorizer::class));
        self::assertSame(
            ['descriptor', 'project', 'providerId', 'readBatch'],
            $this->publicMethodNames(ContentSearchSourceProvider::class),
        );
        self::assertSame(['descriptor', 'rows'], $this->publicMethodNames(ContentDataviewSourceProvider::class));
        self::assertSame(['inspect'], $this->publicMethodNames(ContentLogicalFileInspector::class));
        self::assertTrue((new ReflectionClass(ContentSearchSourceProvider::class))->implementsInterface(ReindexSource::class));
        self::assertTrue((new ReflectionClass(ContentDataviewSourceProvider::class))->implementsInterface(DataviewSourceProvider::class));
        self::assertTrue((new ReflectionClass(ContentAuditEventDescriptor::class))->implementsInterface(AuditEventDescriptor::class));
        $propertyMethod = new ReflectionMethod(PropertyTypeRegistry::class, 'normalizeAndValidate');
        self::assertTrue($propertyMethod->isPublic());
        self::assertSame(4, $propertyMethod->getNumberOfParameters());
    }

    public function test_content_search_projection_constructs_the_exact_search_dto(): void
    {
        $contentProjection = ContentSearchProjection::fromPublished(
            ContentPlatformV1Fixture::publishedArticle(),
        );
        $searchProjection = $contentProjection->toSearchProjection();

        self::assertInstanceOf(SearchProjection::class, $searchProjection);
        self::assertSame(ContentSearchProjection::PROVIDER_ID, $searchProjection->providerId);
        self::assertSame('en', $searchProjection->locale);
        self::assertSame($contentProjection->toArray()['payload'], $searchProjection->payload);
        self::assertArrayNotHasKey('attachments', $searchProjection->payload);
    }

    public function test_content_locale_is_a_lowercase_subset_of_the_accepted_search_locale(): void
    {
        foreach (['en', 'en-us', 'zh-hans'] as $locale) {
            $contentLocale = new ContentLocale($locale);
            $searchProjection = new SearchProjection(
                providerId: 'content.published_items',
                sourceRef: 'content:item:018f62c6-9d27-7d19-b9b1-7cddfbd9a3e1',
                sourceRevision: 1,
                title: 'Title',
                locator: '/content/article/title?locale='.$locale,
                locale: $contentLocale->value,
            );

            self::assertSame($locale, $searchProjection->locale);
        }
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    private function publicMethodNames(string $class): array
    {
        $methods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);

        return $methods;
    }
}
