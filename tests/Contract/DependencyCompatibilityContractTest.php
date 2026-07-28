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
        'larena/access' => '7b15e0ddf8a6d2339ed80963e6f793b6fc6258eb',
        'larena/admin' => '63b822dd334ed6ab02c666f2017e39883cd95b6f',
        'larena/audit' => 'cc6ba3ccf279eefdef3fa3973249629a3a100feb',
        'larena/auth' => '92a32fa8bfb2a8b67676a64253a92490fc4a3874',
        'larena/cockpit' => 'd8074d30727d5c124928b8e47466f063eb746dbf',
        'larena/core' => '68ee3f79ed6313ca9c819340e6be3ff471957f91',
        'larena/dataview' => 'b84e964b4ed78e1ca08a46c88e7651b02744ee47',
        'larena/filesystem' => '5e54a971201f51cf35417b922559f3e52369472d',
        'larena/layout' => 'cb5bdadf588cb8480972279bea3888500dbf9d6e',
        'larena/licensing' => '52d1215a25369cca17d5170bbfcae82d1f6c86d2',
        'larena/link' => 'affc02abad5f3be568ae02c3678abe51d14575a9',
        'larena/property' => '7773692a9e1cf60f641a050e4ebf99e1fe37c159',
        'larena/rest' => 'b11f4338bb2536ee8627c1f2097915876b8afeb6',
        'larena/search' => '9f5c1cf5d2b112751328520eee34826c19dd2535',
        'larena/storage' => '7c606e092591dbcb40fbe7c45ef30bb917f1df68',
        'larena/ui' => '07fff2579344d7c77a28716a74071fb53f0bbfc9',
        'larena/update' => '4c56bb8d26b6259ae71e58512ccadc2529accfec',
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
