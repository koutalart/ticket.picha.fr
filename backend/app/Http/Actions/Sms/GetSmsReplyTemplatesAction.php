<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Sms\SmsReplyTemplateResource;
use HiEvents\Services\Application\Handlers\Sms\GetSmsReplyTemplatesHandler;
use Illuminate\Http\JsonResponse;

class GetSmsReplyTemplatesAction extends BaseAction
{
    public function __construct(
        private readonly GetSmsReplyTemplatesHandler $handler,
    ) {}

    public function __invoke(int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        return $this->resourceResponse(SmsReplyTemplateResource::class, $this->handler->handle($organizerId));
    }
}
