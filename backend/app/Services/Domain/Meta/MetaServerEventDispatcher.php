<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Meta;

use Carbon\Carbon;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\Jobs\Meta\SendMetaServerEventJob;
use HiEvents\Services\Domain\Meta\DTO\MetaServerEventDTO;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues the Conversions API events. Never throws: tracking must not get in the way of a visitor or a buyer.
 *
 * The tracking context is the one built by OrderTrackingContextFactory: advertising identifiers, IP address
 * and user agent are only in it with the marketing consent. Without consent (organizer option), the IP address
 * and user agent of the request are used.
 */
class MetaServerEventDispatcher
{
    public const CHECKOUT_EVENT_ID_PREFIX = 'checkout_';

    public function __construct(
        private readonly MetaServerEventService $service,
        private readonly LoggerInterface $logger,
    ) {}

    public function queuePageEvent(
        int $eventId,
        string $eventName,
        string $metaEventId,
        ?array $trackingContext,
        ?string $clientIpAddress,
        ?string $clientUserAgent,
    ): bool {
        return $this->queue($this->buildEvent($eventId, $eventName, $metaEventId, $trackingContext, $clientIpAddress, $clientUserAgent));
    }

    /**
     * InitiateCheckout when a public order is created, with the pixel's event_id "checkout_<order short id>".
     */
    public function queueInitiateCheckout(
        OrderDomainObject $order,
        ?array $trackingContext,
        ?string $clientIpAddress,
        ?string $clientUserAgent,
    ): bool {
        $numItems = $order->getOrderItems()?->sum(fn (OrderItemDomainObject $item) => $item->getQuantity());

        return $this->queue($this->buildEvent(
            eventId: $order->getEventId(),
            eventName: MetaServerEventService::INITIATE_CHECKOUT,
            metaEventId: self::CHECKOUT_EVENT_ID_PREFIX.$order->getShortId(),
            trackingContext: $trackingContext,
            clientIpAddress: $clientIpAddress,
            clientUserAgent: $clientUserAgent,
            value: $order->getTotalGross() !== null ? (float) $order->getTotalGross() : null,
            currency: $order->getCurrency(),
            numItems: $numItems ? (int) $numItems : null,
        ));
    }

    private function queue(MetaServerEventDTO $event): bool
    {
        try {
            if (! $this->service->isAllowed($event->eventId, $event->consent)) {
                return false;
            }

            dispatch(new SendMetaServerEventJob($event));

            return true;
        } catch (Throwable $exception) {
            $this->logger->warning('Meta Conversions API event could not be queued', [
                'event_name' => $event->eventName,
                'event_id' => $event->eventId,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function buildEvent(
        int $eventId,
        string $eventName,
        string $metaEventId,
        ?array $trackingContext,
        ?string $clientIpAddress,
        ?string $clientUserAgent,
        ?float $value = null,
        ?string $currency = null,
        ?int $numItems = null,
    ): MetaServerEventDTO {
        $context = $trackingContext ?? [];
        $consent = ($context['consent'] ?? false) === true;

        return new MetaServerEventDTO(
            eventId: $eventId,
            eventName: $eventName,
            metaEventId: $metaEventId,
            eventTime: Carbon::now()->getTimestamp(),
            consent: $consent,
            eventSourceUrl: $consent ? ($context['event_source_url'] ?? null) : null,
            clientIpAddress: $context['client_ip_address'] ?? (filter_var($clientIpAddress, FILTER_VALIDATE_IP) ?: null),
            clientUserAgent: $context['client_user_agent'] ?? ($clientUserAgent ? mb_substr($clientUserAgent, 0, 512) : null),
            fbp: $consent ? ($context['fbp'] ?? null) : null,
            fbc: $consent ? MetaClickId::fbc($context['fbc'] ?? null, $context['fbclid'] ?? null) : null,
            value: $value,
            currency: $currency,
            numItems: $numItems,
        );
    }
}
