<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Sms\SmsCampaignRecipientResource;
use HiEvents\Services\Application\Handlers\Sms\GetSmsCampaignRecipientsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GetSmsCampaignRecipientsAction extends BaseAction
{
    public function __construct(
        private readonly GetSmsCampaignRecipientsHandler $handler,
    ) {}

    public function __invoke(int $organizerId, int $campaignId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $recipients = $this->handler->handle($organizerId, $campaignId);
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->resourceResponse(SmsCampaignRecipientResource::class, $recipients);
    }
}
