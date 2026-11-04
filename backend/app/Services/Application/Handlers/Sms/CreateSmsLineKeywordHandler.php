<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\SmsLineKeywordDomainObject;
use HiEvents\Exceptions\SmsLineKeywordValidationException;
use HiEvents\Services\Domain\Sms\DTO\CreateSmsLineKeywordDTO;
use HiEvents\Services\Domain\Sms\SmsLineKeywordService;

class CreateSmsLineKeywordHandler
{
    public function __construct(
        private readonly SmsLineKeywordService $keywordService,
    ) {}

    /**
     * @throws SmsLineKeywordValidationException
     */
    public function handle(CreateSmsLineKeywordDTO $dto): SmsLineKeywordDomainObject
    {
        return $this->keywordService->create($dto);
    }
}
