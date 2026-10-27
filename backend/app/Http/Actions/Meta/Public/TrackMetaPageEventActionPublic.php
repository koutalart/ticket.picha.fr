<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Meta\Public;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Meta\TrackMetaPageEventRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Application\Handlers\Meta\TrackMetaPageEventHandler;
use Illuminate\Http\Response;

/**
 * Server copy of a PageView or ViewContent pixel event (same event_id), sent through the Conversions API.
 */
class TrackMetaPageEventActionPublic extends BaseAction
{
    public function __construct(
        private readonly TrackMetaPageEventHandler $handler,
    ) {}

    public function __invoke(TrackMetaPageEventRequest $request, int $eventId): Response
    {
        $this->handler->handle(
            eventId: $eventId,
            eventName: $request->validated('event_name'),
            metaEventId: $request->validated('meta_event_id'),
            input: [
                'consent' => $request->boolean('consent'),
                'event_source_url' => $request->validated('event_source_url'),
                'fbp' => $request->validated('fbp'),
                'fbc' => $request->validated('fbc'),
                'fbclid' => $request->validated('fbclid'),
            ],
            clientIpAddress: $request->ip(),
            clientUserAgent: $request->userAgent(),
        );

        return $this->noContentResponse(ResponseCodes::HTTP_ACCEPTED);
    }
}
