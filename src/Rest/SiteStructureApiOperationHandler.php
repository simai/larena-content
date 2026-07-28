<?php

declare(strict_types=1);

namespace Larena\Content\Rest;

use InvalidArgumentException;
use Larena\Access\Exceptions\AccessMutationRejected;
use Larena\Auth\ValueObjects\EntryObject;
use Larena\Content\Contracts\SiteStructureService;
use Larena\Content\Exceptions\ContentConflict;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\SiteSeoMetadata;
use Larena\Content\ValueObjects\SiteStructureNode;
use Larena\Content\ValueObjects\SiteStructureRevision;
use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Rest\Contracts\OperationContextMetadata;
use Larena\Rest\Contracts\OperationHandlerRegistry;
use Larena\Rest\Exceptions\ApiOperationException;

final readonly class SiteStructureApiOperationHandler
{
    private const array OPERATIONS = [
        'content.structure_admin.read',
        'content.structure_admin.revisions.read',
        'content.structure_admin.replace',
        'content.structure_admin.submit_review',
        'content.structure_admin.publish',
        'content.structure_admin.restore',
    ];

    public function __construct(private SiteStructureService $structures)
    {
    }

    /** @param callable():mixed $resolver */
    public static function registerLazy(OperationHandlerRegistry $handlers, callable $resolver): void
    {
        foreach (self::OPERATIONS as $reference) {
            $handlers->register($reference, static function (array $input, OperationDescriptor $descriptor, OperationContext $context) use ($resolver, $reference): array {
                $handler = $resolver();
                if (!$handler instanceof self) {
                    throw new InvalidArgumentException('content_structure_api_handler_resolver_invalid');
                }

                return $handler->dispatch($reference, $input, $descriptor, $context);
            });
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function dispatch(string $reference, array $input, OperationDescriptor $descriptor, OperationContext $context): array
    {
        if ($descriptor->name !== $reference) {
            throw new ApiOperationException('content_structure_api_operation_mismatch', 409, 'The site-structure declaration does not match its handler.');
        }
        $actor = $this->actor($context);
        $path = $this->object($input, 'path');
        $body = $this->object($input, 'body');
        try {
            $revision = match ($reference) {
                'content.structure_admin.read' => $this->structures->read($actor),
                'content.structure_admin.revisions.read' => $this->structures->revision($this->integer($path, 'revision'), $actor),
                'content.structure_admin.replace' => $this->structures->replace(
                    $this->integer($body, 'expected_revision'),
                    array_map(static fn (array $node): SiteStructureNode => SiteStructureNode::fromArray($node), $this->list($body, 'nodes')),
                    array_map(static fn (array $seo): SiteSeoMetadata => SiteSeoMetadata::fromArray($seo), $this->list($body, 'seo')),
                    $actor,
                ),
                'content.structure_admin.submit_review' => $this->structures->submitForReview($this->integer($body, 'expected_revision'), $actor),
                'content.structure_admin.publish' => $this->structures->publish($this->integer($body, 'expected_revision'), $actor),
                'content.structure_admin.restore' => $this->structures->restore($this->integer($path, 'revision'), $this->integer($body, 'expected_revision'), $actor),
                default => throw new ApiOperationException('content_structure_api_operation_unknown', 404, 'The site-structure operation is not registered.'),
            };

            return ['structure' => in_array($reference, [
                'content.structure_admin.read',
                'content.structure_admin.revisions.read',
            ], true) ? $this->encode($revision) : [
                'structure_ref' => $revision->structureRef,
                'revision' => $revision->revision,
                'status' => $revision->status,
            ]];
        } catch (AccessMutationRejected) {
            throw new ApiOperationException('access_denied', 403, 'The authenticated actor may not perform this site-structure operation.');
        } catch (ContentConflict $exception) {
            throw new ApiOperationException('content_structure_stale_revision', 409, 'The site structure changed concurrently.');
        } catch (ContentRejected $exception) {
            $status = str_contains($exception->reasonCode(), 'not_found') ? 404 : (str_contains($exception->reasonCode(), 'conflict') ? 409 : 422);
            throw new ApiOperationException($exception->reasonCode(), $status, 'The site-structure operation failed closed.');
        } catch (InvalidArgumentException|\ValueError) {
            throw new ApiOperationException('content_structure_api_request_invalid', 422, 'The site-structure request is invalid.');
        }
    }

    /** @return array<string, mixed> */
    private function encode(SiteStructureRevision $revision): array
    {
        return [
            'structure_ref' => $revision->structureRef,
            'revision' => $revision->revision,
            'status' => $revision->status,
            'published_revision' => $revision->publishedRevision,
            'nodes' => array_map(static fn (SiteStructureNode $node): array => $node->toArray(), $revision->nodes),
            'seo' => array_map(static fn (SiteSeoMetadata $seo): array => $seo->toArray(), $revision->seo),
            'created_by' => $revision->createdBy,
            'created_at' => $revision->createdAt->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    private function actor(OperationContext $context): ActorContext
    {
        $entry = $context->metadata[OperationContextMetadata::AUTHENTICATED_ACTOR] ?? null;
        if (!$entry instanceof EntryObject || $entry->subjectRef !== $context->actorId) {
            throw new ApiOperationException('content_structure_api_session_context_invalid', 403, 'A validated administrator session is required.');
        }

        return new ActorContext('user', $context->actorId, $context->correlationId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function object(array $input, string $key): array
    {
        $value = $input[$key] ?? null;
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ApiOperationException('content_structure_api_request_invalid', 422, 'The validated site-structure request is malformed.');
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private function integer(array $input, string $key): int
    {
        if (!array_key_exists($key, $input) || !is_int($input[$key])) {
            throw new InvalidArgumentException('content_structure_api_request_invalid');
        }

        return $input[$key];
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array<string, mixed>>
     */
    private function list(array $input, string $key): array
    {
        $value = $input[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('content_structure_api_request_invalid');
        }
        foreach ($value as $entry) {
            if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) {
                throw new InvalidArgumentException('content_structure_api_request_invalid');
            }
        }

        return $value;
    }
}
