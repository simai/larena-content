<?php

declare(strict_types=1);

namespace Larena\Content\Runtime;

use Larena\Content\Exceptions\ContentRejected;
use Larena\Content\ValueObjects\ActorContext;
use Larena\Content\ValueObjects\ContentAttachmentPlacement;
use Larena\Content\ValueObjects\ContentFieldDefinition;
use Larena\Content\ValueObjects\ContentProjectionContract;

final class ContentInputGuard
{
    public const int MAX_FIELDS = 100;
    public const int MAX_VALUE_KEYS = 100;
    public const int MAX_STRING_CODEPOINTS = 65_536;
    public const int MAX_VALUES_JSON_BYTES = 1_048_576;
    public const int MAX_PROJECTION_JSON_BYTES = 16_384;
    public const int MAX_SAFE_METADATA_JSON_BYTES = 16_384;
    public const int MAX_PAGE_SIZE = 100;
    public const int MAX_ATTACHMENTS = 100;
    public const int MAX_MUTABLE_REVISION = PHP_INT_MAX - 1;

    /** @var list<string> */
    private const SAFE_TEXT_METADATA = [
        'label',
        'plural_label',
        'description',
        'icon',
        'group',
    ];

    /** @var list<string> */
    private const SAFE_TYPE_METADATA = [
        'label',
        'plural_label',
        'description',
        'icon',
        'group',
        'sort',
        'hidden',
    ];

    private ContentCanonicalJson $canonicalJson;

    public function __construct(?ContentCanonicalJson $canonicalJson = null)
    {
        $this->canonicalJson = $canonicalJson ?? new ContentCanonicalJson();
    }

    public function assertActor(ActorContext $actor): void
    {
        if (
            $actor->actorType !== 'user'
            || preg_match('/\Auser:admin_identity:[1-9][0-9]*\z/D', $actor->actorRef) !== 1
        ) {
            throw new ContentRejected(
                'actor_invalid',
                'Content protected operations require a canonical administrator identity.',
            );
        }
    }

    /**
     * @param array<array-key, mixed> $fields
     */
    public function assertFields(array $fields): void
    {
        if (!array_is_list($fields) || $fields === [] || count($fields) > self::MAX_FIELDS) {
            throw new ContentRejected(
                'field_limit_exceeded',
                sprintf('A Content type requires between 1 and %d fields.', self::MAX_FIELDS),
            );
        }

        foreach ($fields as $field) {
            if (!$field instanceof ContentFieldDefinition) {
                throw new ContentRejected(
                    'field_definition_invalid',
                    'Content fields must be exact field definition value objects.',
                );
            }

            if (
                $field->propertyType === 'string'
                && ($field->constraints['max_length'] ?? 0) > self::MAX_STRING_CODEPOINTS
            ) {
                throw new ContentRejected(
                    'string_constraint_too_large',
                    sprintf(
                        'A Content string max_length may not exceed %d Unicode code points.',
                        self::MAX_STRING_CODEPOINTS,
                    ),
                );
            }
        }
    }

    /**
     * @param array<array-key, mixed> $values
     */
    public function assertSubmittedValues(array $values): void
    {
        $this->assertValuesShape($values);
        $this->canonicalJson->assertMaximumBytes(
            $values,
            self::MAX_VALUES_JSON_BYTES,
            'submitted_values_too_large',
        );
    }

    /**
     * @param array<array-key, mixed> $values
     */
    public function assertValues(array $values): void
    {
        $this->assertSubmittedValues($values);
    }

    /**
     * Storage normalization is independently owned, so the returned value is
     * rechecked as an in-transaction postcondition.
     *
     * @param array<array-key, mixed> $values
     */
    public function assertNormalizedValues(array $values): void
    {
        $this->assertValuesShape($values);
        $this->canonicalJson->assertMaximumBytes(
            $values,
            self::MAX_VALUES_JSON_BYTES,
            'normalized_values_too_large',
        );
    }

    public function assertProjectionContract(ContentProjectionContract $contract): void
    {
        $this->canonicalJson->assertMaximumBytes(
            $contract->toArray(),
            self::MAX_PROJECTION_JSON_BYTES,
            'projection_contract_too_large',
        );
    }

    /**
     * @param array<array-key, mixed> $metadata
     */
    public function assertSafeTypeMetadata(array $metadata): void
    {
        if ($metadata !== [] && array_is_list($metadata)) {
            throw new ContentRejected(
                'safe_type_metadata_invalid',
                'Content type metadata must be a keyed object.',
            );
        }

        foreach ($metadata as $key => $value) {
            if (!is_string($key) || !in_array($key, self::SAFE_TYPE_METADATA, true)) {
                throw new ContentRejected(
                    'safe_type_metadata_invalid',
                    'Content type metadata contains a key outside the frozen allowlist.',
                );
            }

            if (in_array($key, self::SAFE_TEXT_METADATA, true)) {
                if ($value !== null && !is_string($value)) {
                    throw new ContentRejected(
                        'safe_type_metadata_invalid',
                        'Content type text metadata must be a nullable string.',
                    );
                }

                if (is_string($value)) {
                    $this->assertSafeMetadataText($value);
                }

                continue;
            }

            if (
                ($key === 'sort' && $value !== null && !is_int($value))
                || ($key === 'hidden' && $value !== null && !is_bool($value))
            ) {
                throw new ContentRejected(
                    'safe_type_metadata_invalid',
                    'Content type metadata does not match its frozen scalar type.',
                );
            }
        }

        $this->canonicalJson->assertMaximumBytes(
            $metadata,
            self::MAX_SAFE_METADATA_JSON_BYTES,
            'safe_type_metadata_too_large',
        );
    }

    public function assertPageLimit(int $limit): void
    {
        if ($limit < 1 || $limit > self::MAX_PAGE_SIZE) {
            throw new ContentRejected(
                'page_limit_invalid',
                sprintf('Content page sizes must be between 1 and %d.', self::MAX_PAGE_SIZE),
            );
        }
    }

    /**
     * @param array<array-key, mixed> $placements
     */
    public function assertAttachmentManifest(array $placements): void
    {
        if (!array_is_list($placements)) {
            throw new ContentRejected(
                'attachment_manifest_invalid',
                'A Content attachment manifest must be an ordered list.',
            );
        }

        if (count($placements) > self::MAX_ATTACHMENTS) {
            throw new ContentRejected(
                'attachment_limit_exceeded',
                sprintf('A Content revision may contain at most %d attachments.', self::MAX_ATTACHMENTS),
            );
        }

        $identities = [];

        foreach ($placements as $position => $placement) {
            if (
                !$placement instanceof ContentAttachmentPlacement
                || $placement->position !== $position
            ) {
                throw new ContentRejected(
                    'attachment_manifest_invalid',
                    'Content attachment positions must be contiguous and start at zero.',
                );
            }

            $identity = $placement->logicalFileRef."\0".$placement->role;

            if (isset($identities[$identity])) {
                throw new ContentRejected(
                    'attachment_manifest_invalid',
                    'A Content attachment manifest cannot repeat one logical file and role.',
                );
            }

            $identities[$identity] = true;
        }
    }

    public function assertLogicalFileRef(string $logicalFileRef): void
    {
        try {
            ContentAttachmentPlacement::assertLogicalFileRef($logicalFileRef);
        } catch (\InvalidArgumentException $exception) {
            throw new ContentRejected(
                'logical_file_ref_invalid',
                'Content requires a canonical lowercase bare logical-file UUID.',
                $exception,
            );
        }
    }

    public function assertMutableRevision(int $revision): void
    {
        if ($revision < 0 || $revision > self::MAX_MUTABLE_REVISION) {
            throw new ContentRejected(
                'revision_limit_exceeded',
                'The Content revision cannot be incremented safely.',
            );
        }
    }

    public function assertCanIncrementRevision(int $currentRevision): void
    {
        if ($currentRevision < 1 || $currentRevision >= self::MAX_MUTABLE_REVISION) {
            throw new ContentRejected(
                'revision_limit_exceeded',
                'The Content or Storage revision cannot be incremented safely.',
            );
        }
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private function assertValuesShape(array $values): void
    {
        if (
            ($values !== [] && array_is_list($values))
            || count($values) > self::MAX_VALUE_KEYS
        ) {
            throw new ContentRejected(
                'value_limit_exceeded',
                sprintf('Content accepts at most %d keyed values.', self::MAX_VALUE_KEYS),
            );
        }

        foreach ($values as $key => $value) {
            if (
                !is_string($key)
                || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $key) !== 1
                || !($value === null || is_string($value) || is_int($value) || is_bool($value))
            ) {
                throw new ContentRejected(
                    'submitted_value_invalid',
                    'Content values must be keyed scalar values from the frozen Property set.',
                );
            }

            if (is_string($value)) {
                if (
                    preg_match('//u', $value) !== 1
                    || mb_strlen($value, 'UTF-8') > self::MAX_STRING_CODEPOINTS
                ) {
                    throw new ContentRejected(
                        'string_value_too_long',
                        sprintf(
                            'One Content string value may contain at most %d valid Unicode code points.',
                            self::MAX_STRING_CODEPOINTS,
                        ),
                    );
                }
            }
        }
    }

    private function assertSafeMetadataText(string $value): void
    {
        if (
            preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || preg_match('/<\s*script\b/i', $value) === 1
            || preg_match('/\A\s*(?:javascript\s*:|data\s*:)/i', $value) === 1
        ) {
            throw new ContentRejected(
                'safe_type_metadata_invalid',
                'Content type metadata must be valid, inert UTF-8 text.',
            );
        }
    }
}
