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
        'larena/access' => '0c504fc86a02cd499664a8233d24c54a6854b788',
        'larena/admin' => '7c5b877c1dcd495d52cbd55b7d0e961986c2f85d',
        'larena/audit' => 'cc6ba3ccf279eefdef3fa3973249629a3a100feb',
        'larena/auth' => 'a6c44a7e1145b502bc3d8d6805e6e23f8f09e420',
        'larena/cockpit' => 'd8074d30727d5c124928b8e47466f063eb746dbf',
        'larena/core' => '47123f2e79fd8852c91af7eafb4b03754425ac4a',
        'larena/dataview' => '685919570a7a57654528c4577bd119e436189e23',
        'larena/filesystem' => '996093e547237f740af8c2cb2a0fca96cabd4c96',
        'larena/layout' => '30c4ab5f452584d7c95d6186ed4d4b9ab999a5f5',
        'larena/licensing' => '52d1215a25369cca17d5170bbfcae82d1f6c86d2',
        'larena/link' => 'affc02abad5f3be568ae02c3678abe51d14575a9',
        'larena/property' => 'b0253f5f2546ea8bcb8e52b47f31f9f71ae627c7',
        'larena/rest' => 'b11f4338bb2536ee8627c1f2097915876b8afeb6',
        'larena/search' => '6eac910455057daeab4eab01efd5208a2163da04',
        'larena/storage' => 'b361f6e16ed8b5e18d78b47b9d9fa6620b588a16',
        'larena/ui' => 'cd7757b3d761a869b7f94226f78215499e6946ed',
        'larena/update' => 'd9fda5157964d600c0311502bd36daac5aed38d5',
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
                'listCurrentRecords',
                'projectPublicVersion',
                'readAdminCurrentVersion',
                'readAdminVersion',
                'registerSchemaVersion',
                'schemaVersion',
                'transition',
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
