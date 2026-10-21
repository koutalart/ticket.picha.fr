<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Sms\SmsOptOutResource;
use HiEvents\Services\Application\Handlers\Sms\GetSmsOptOutsHandler;
use HiEvents\Services\Domain\Sms\DTO\SmsOptOutSearchDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetSmsOptOutsAction extends BaseAction
{
    public function __construct(
        private readonly GetSmsOptOutsHandler $handler,
    ) {}

    public function __invoke(Request $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        return $this->resourceResponse(
            SmsOptOutResource::class,
            $this->handler->handle($organizerId, new SmsOptOutSearchDTO(
                query: mb_substr((string) $request->query('query', ''), 0, 100),
                page: max(1, (int) $request->query('page', 1)),
                perPage: min(100, max(1, (int) $request->query('per_page', 50))),
            )),
        );
    }
}
