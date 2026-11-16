<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Event\DTO\DraftEvent;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\QuestionTypeEnum;

class DraftQuestionDTO extends BaseDataObject
{
    /**
     * @param  string[]|null  $options
     */
    public function __construct(
        public readonly string $title,
        public readonly QuestionTypeEnum $type,
        public readonly bool $required,
        public readonly ?array $options = null,
        public readonly ?string $description = null,
    ) {}
}
