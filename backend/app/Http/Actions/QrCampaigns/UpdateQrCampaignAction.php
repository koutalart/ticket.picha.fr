<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\QrCampaigns;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\QrCampaign\UpsertQrCampaignRequest;
use HiEvents\Resources\QrCampaign\QrCampaignResource;
use HiEvents\Services\Application\Handlers\QrCampaign\UpsertQrCampaignHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class UpdateQrCampaignAction extends BaseAction
{
    public function __construct(
        private readonly UpsertQrCampaignHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(UpsertQrCampaignRequest $request, int $organizerId, int $campaignId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $campaign = $this->handler->rename($organizerId, $campaignId, $request->validated('name'));
        } catch (ResourceConflictException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        return $this->resourceResponse(QrCampaignResource::class, $campaign);
    }
}
