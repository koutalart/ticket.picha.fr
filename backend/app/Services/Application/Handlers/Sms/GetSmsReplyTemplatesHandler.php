<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\SmsReplyTemplateDomainObject;
use HiEvents\Services\Domain\Sms\SmsReplyTemplateService;
use Illuminate\Support\Collection;

class GetSmsReplyTemplatesHandler
{
    public function __construct(
        private readonly SmsReplyTemplateService $templateService,
    ) {}

    /**
     * @return Collection<SmsReplyTemplateDomainObject>
     */
    public function handle(int $organizerId): Collection
    {
        return $this->templateService->list($organizerId);
    }
}
