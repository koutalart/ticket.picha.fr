<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\AI\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class ClaudeStructuredAnswerDTO extends BaseDataObject
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly array $data,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
    ) {}
}
