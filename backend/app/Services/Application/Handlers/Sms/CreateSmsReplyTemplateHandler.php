<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\SmsReplyTemplateDomainObject;
use HiEvents\Exceptions\SmsReplyTemplateValidationException;
use HiEvents\Services\Domain\Sms\DTO\SmsReplyTemplateDTO;
use HiEvents\Services\Domain\Sms\SmsReplyTemplateService;

class CreateSmsReplyTemplateHandler
{
    public function __construct(
        private readonly SmsReplyTemplateService $templateService,
    ) {}

    /**
     * @throws SmsReplyTemplateValidationException
     */
    public function handle(SmsReplyTemplateDTO $dto): SmsReplyTemplateDomainObject
    {
        return $this->templateService->create($dto);
    }
}
