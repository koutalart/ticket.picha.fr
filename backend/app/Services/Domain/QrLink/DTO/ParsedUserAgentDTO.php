<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\QrLink\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class ParsedUserAgentDTO extends BaseDataObject
{
    public function __construct(
        public bool $isBot,
        public ?string $deviceType,
        public ?string $os,
        public ?string $browser,
    ) {}
}
