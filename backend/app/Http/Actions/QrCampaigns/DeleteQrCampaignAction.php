<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\QrCampaigns;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\QrCampaign\DeleteQrCampaignHandler;
use Illuminate\Http\Response;

class DeleteQrCampaignAction extends BaseAction
{
    public function __construct(
        private readonly DeleteQrCampaignHandler $handler,
    ) {}

    public function __invoke(int $organizerId, int $campaignId): Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        return $this->handler->handle($organizerId, $campaignId)
            ? $this->deletedResponse()
            : $this->notFoundResponse();
    }
}
