<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Event\DTO\DraftEvent;

use HiEvents\DataTransferObjects\BaseDataObject;

class AppliedEventPosterDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $accent,
        public readonly bool $video,
    ) {}
}
