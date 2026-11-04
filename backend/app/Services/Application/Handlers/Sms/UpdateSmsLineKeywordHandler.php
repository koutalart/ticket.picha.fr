<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\SmsLineKeywordDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Services\Domain\Sms\DTO\UpdateSmsLineKeywordDTO;
use HiEvents\Services\Domain\Sms\SmsLineKeywordService;

class UpdateSmsLineKeywordHandler
{
    public function __construct(
        private readonly SmsLineKeywordService $keywordService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(UpdateSmsLineKeywordDTO $dto): SmsLineKeywordDomainObject
    {
        return $this->keywordService->update($dto);
    }
}
