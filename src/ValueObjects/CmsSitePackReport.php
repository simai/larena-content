<?php

declare(strict_types=1);

namespace Larena\Content\ValueObjects;

final readonly class CmsSitePackReport
{
    /**
     * @param array<string, int> $counts
     */
    public function __construct(
        public string $packageRef,
        public string $digest,
        public string $status,
        public array $counts,
    ) {
        if (
            preg_match('/\Acms-[a-f0-9]{64}\.sitepack\z/D', $packageRef) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
            || !in_array($status, ['exported', 'verified', 'planned', 'imported'], true)
        ) {
            throw new \InvalidArgumentException('content_sitepack_report_invalid');
        }
        foreach ($counts as $key => $value) {
            if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $key) !== 1 || $value < 0) {
                throw new \InvalidArgumentException('content_sitepack_report_counts_invalid');
            }
        }
    }

    /** @return array{package_ref:string,digest:string,status:string,counts:array<string,int>} */
    public function toArray(): array
    {
        return [
            'package_ref' => $this->packageRef,
            'digest' => $this->digest,
            'status' => $this->status,
            'counts' => $this->counts,
        ];
    }
}
