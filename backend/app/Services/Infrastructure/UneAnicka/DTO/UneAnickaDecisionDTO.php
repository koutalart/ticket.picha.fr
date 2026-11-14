<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\UneAnicka\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class UneAnickaDecisionDTO extends BaseDataObject
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $rule,
        public readonly string $reason,
    ) {}

    public static function allowedByDefault(string $reason): self
    {
        return new self(allowed: true, rule: '-', reason: $reason);
    }
}
