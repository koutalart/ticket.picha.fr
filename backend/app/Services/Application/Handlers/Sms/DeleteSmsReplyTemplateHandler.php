<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Services\Domain\Sms\SmsReplyTemplateService;

class DeleteSmsReplyTemplateHandler
{
    public function __construct(
        private readonly SmsReplyTemplateService $templateService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $organizerId, int $templateId): void
    {
        $this->templateService->delete($organizerId, $templateId);
    }
}
