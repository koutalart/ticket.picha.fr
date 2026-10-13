<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Meta;

use Carbon\Carbon;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrganizerSettingDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\MetaConversionsApiException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Services\Infrastructure\Meta\MetaConversionsApiClient;
use Psr\Log\LoggerInterface;

class MetaPurchaseConversionService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrganizerSettingsRepositoryInterface $organizerSettingsRepository,
        private readonly MetaPurchaseEventBuilder $eventBuilder,
        private readonly MetaConversionsApiClient $client,
        private readonly LoggerInterface $logger,
    ) {}

    public static function isEligible(OrderDomainObject $order): bool
    {
        return $order->getStatus() === OrderStatus::COMPLETED->name
            && $order->getMetaCapiSentAt() === null
            && (($order->getTrackingContext() ?? [])['consent'] ?? false) === true;
    }

    /**
     * @throws MetaConversionsApiException
     */
    public function sendPurchase(int $orderId): bool
    {
        /** @var OrderDomainObject|null $order */
        $order = $this->orderRepository
            ->loadRelation(OrderItemDomainObject::class)
            ->findFirst($orderId);

        if ($order === null || ! self::isEligible($order)) {
            return false;
        }

        $event = $this->eventRepository->findById($order->getEventId());

        /** @var OrganizerSettingDomainObject|null $settings */
        $settings = $this->organizerSettingsRepository->findFirstWhere([
            OrganizerSettingDomainObjectAbstract::ORGANIZER_ID => $event->getOrganizerId(),
        ]);

        if ($settings === null || ! $settings->isMetaConversionsApiConfigured()) {
            return false;
        }

        $eventsReceived = $this->client->sendEvents(
            pixelId: $settings->getEnabledFacebookPixelId(),
            accessToken: $settings->getMetaConversionsApiToken(),
            events: [$this->eventBuilder->build($order, $event)],
            testEventCode: $settings->getMetaTestEventCode(),
        );

        $this->orderRepository->updateFromArray($order->getId(), [
            OrderDomainObjectAbstract::META_CAPI_SENT_AT => Carbon::now(),
        ]);

        $this->logger->info('Meta Conversions API purchase sent', [
            'order_id' => $order->getId(),
            'events_received' => $eventsReceived,
        ]);

        return true;
    }
}
