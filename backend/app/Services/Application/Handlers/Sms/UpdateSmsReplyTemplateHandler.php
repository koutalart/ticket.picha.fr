<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\SmsReplyTemplateDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SmsReplyTemplateValidationException;
use HiEvents\Services\Domain\Sms\DTO\SmsReplyTemplateDTO;
use HiEvents\Services\Domain\Sms\SmsReplyTemplateService;

class UpdateSmsReplyTemplateHandler
{
    public function __construct(
        private readonly SmsReplyTemplateService $templateService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws SmsReplyTemplateValidationException
     */
    public function handle(int $templateId, SmsReplyTemplateDTO $dto): SmsReplyTemplateDomainObject
    {
        return $this->templateService->update($templateId, $dto);
    }
}
