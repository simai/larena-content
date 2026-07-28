<?php

declare(strict_types=1);

namespace Larena\Content\Rest;

use InvalidArgumentException;
use Larena\Access\Exceptions\AccessMutationRejected;
use Larena\Auth\ValueObjects\EntryObject;
use Larena\Content\Contracts\CmsSitePackService;
use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\ValueObjects\ActorContext;
use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Rest\Contracts\OperationContextMetadata;
use Larena\Rest\Contracts\OperationHandlerRegistry;
use Larena\Rest\Exceptions\ApiOperationException;

final readonly class CmsSitePackApiOperationHandler
{
    private const array OPERATIONS = [
        'content.sitepack_admin.export',
        'content.sitepack_admin.verify',
        'content.sitepack_admin.import.dry_run',
        'content.sitepack_admin.import.apply',
    ];

    public function __construct(private CmsSitePackService $sitePacks)
    {
    }

    /** @param callable():mixed $resolver */
    public static function registerLazy(OperationHandlerRegistry $handlers, callable $resolver): void
    {
        foreach (self::OPERATIONS as $reference) {
            $handlers->register($reference, static function (array $input, OperationDescriptor $descriptor, OperationContext $context) use ($resolver, $reference): array {
                $handler = $resolver();
                if (!$handler instanceof self) {
                    throw new InvalidArgumentException('content_sitepack_api_handler_resolver_invalid');
                }

                return $handler->dispatch($reference, $input, $descriptor, $context);
            });
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function dispatch(string $reference, array $input, OperationDescriptor $descriptor, OperationContext $context): array
    {
        if ($descriptor->name !== $reference) {
            throw new ApiOperationException('content_sitepack_api_operation_mismatch', 409, 'The SitePack operation declaration does not match its handler.');
        }
        $actor = $this->actor($context);
        $body = $input['body'] ?? null;
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new ApiOperationException('content_sitepack_api_request_invalid', 422, 'The validated SitePack request is malformed.');
        }
        try {
            $report = match ($reference) {
                'content.sitepack_admin.export' => $this->sitePacks->export($actor),
                'content.sitepack_admin.verify' => $this->sitePacks->verify($this->packageRef($body), $actor),
                'content.sitepack_admin.import.dry_run' => $this->sitePacks->dryRun($this->packageRef($body), $actor),
                'content.sitepack_admin.import.apply' => $this->sitePacks->import($this->packageRef($body), $actor),
                default => throw new ApiOperationException('content_sitepack_api_operation_unknown', 404, 'The SitePack operation is not registered.'),
            };

            return ['sitepack' => $report->toArray()];
        } catch (AccessMutationRejected) {
            throw new ApiOperationException('access_denied', 403, 'The authenticated actor may not perform this SitePack operation.');
        } catch (ContentRejected $exception) {
            $status = str_contains($exception->reasonCode(), 'conflict') ? 409 : 422;
            throw new ApiOperationException($exception->reasonCode(), $status, 'The SitePack operation failed closed.');
        } catch (InvalidArgumentException) {
            throw new ApiOperationException('content_sitepack_api_request_invalid', 422, 'The SitePack request is invalid.');
        }
    }

    private function actor(OperationContext $context): ActorContext
    {
        $entry = $context->metadata[OperationContextMetadata::AUTHENTICATED_ACTOR] ?? null;
        if (!$entry instanceof EntryObject || $entry->subjectRef !== $context->actorId) {
            throw new ApiOperationException('content_sitepack_api_session_context_invalid', 403, 'A validated administrator session is required.');
        }

        return new ActorContext('user', $context->actorId, $context->correlationId);
    }

    /** @param array<string,mixed> $body */
    private function packageRef(array $body): string
    {
        $ref = $body['package_ref'] ?? null;
        if (!is_string($ref) || preg_match('/\Acms-[a-f0-9]{64}\.sitepack\z/D', $ref) !== 1) {
            throw new InvalidArgumentException('content_sitepack_package_ref_invalid');
        }

        return $ref;
    }
}
