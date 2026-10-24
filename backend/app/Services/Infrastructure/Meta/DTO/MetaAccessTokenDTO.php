<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Meta\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class MetaAccessTokenDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $accessToken,
        public readonly ?int $expiresIn = null,
    ) {}
}
