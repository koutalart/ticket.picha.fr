<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\QrLinks;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\QrLink\GetQrLinkStatsHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GetQrLinkStatsAction extends BaseAction
{
    private const ALLOWED_DAYS = [7, 30, 90, 365];

    public function __construct(
        private readonly GetQrLinkStatsHandler $handler,
    ) {}

    public function __invoke(Request $request, int $organizerId, int $qrLinkId): JsonResponse|Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        $days = (int) $request->query('days', 30);

        try {
            $stats = $this->handler->handle(
                organizerId: $organizerId,
                qrLinkId: $qrLinkId,
                days: in_array($days, self::ALLOWED_DAYS, true) ? $days : null,
            );
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->jsonResponse($stats->toArray(), wrapInData: true);
    }
}
