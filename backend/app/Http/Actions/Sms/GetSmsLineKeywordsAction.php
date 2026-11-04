<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Sms\SmsLineKeywordsResource;
use HiEvents\Services\Application\Handlers\Sms\GetSmsLineKeywordsHandler;
use Illuminate\Http\JsonResponse;

class GetSmsLineKeywordsAction extends BaseAction
{
    public function __construct(
        private readonly GetSmsLineKeywordsHandler $handler,
    ) {}

    public function __invoke(int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        return $this->resourceResponse(SmsLineKeywordsResource::class, $this->handler->handle($organizerId));
    }
}
