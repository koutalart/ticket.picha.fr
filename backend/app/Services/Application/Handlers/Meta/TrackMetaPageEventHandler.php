<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Meta;

use HiEvents\Services\Domain\Meta\MetaServerEventDispatcher;
use HiEvents\Services\Domain\Meta\OrderTrackingContextFactory;

class TrackMetaPageEventHandler
{
    public function __construct(
        private readonly OrderTrackingContextFactory $trackingContextFactory,
        private readonly MetaServerEventDispatcher $dispatcher,
    ) {}

    /**
     * @param  array{consent: bool, event_source_url?: ?string, fbp?: ?string, fbc?: ?string, fbclid?: ?string}  $input
     */
    public function handle(
        int $eventId,
        string $eventName,
        string $metaEventId,
        array $input,
        ?string $clientIpAddress,
        ?string $clientUserAgent,
    ): bool {
        $context = $this->trackingContextFactory->create($input, $clientIpAddress, $clientUserAgent);

        return $this->dispatcher->queuePageEvent(
            eventId: $eventId,
            eventName: $eventName,
            metaEventId: $metaEventId,
            trackingContext: $context,
            clientIpAddress: $clientIpAddress,
            clientUserAgent: $clientUserAgent,
        );
    }
}
