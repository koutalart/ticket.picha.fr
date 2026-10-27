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
use HiEvents\Repository\Interfaces\SmsCustomerDirectoryRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderAttendeeDTO;
use HiEvents\Services\Infrastructure\Meta\MetaConversionsApiClient;
use Psr\Log\LoggerInterface;
use Throwable;

class MetaPurchaseConversionService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrganizerSettingsRepositoryInterface $organizerSettingsRepository,
        private readonly MetaPurchaseEventBuilder $eventBuilder,
        private readonly MetaConversionsApiClient $client,
        private readonly LoggerInterface $logger,
        private readonly SmsCustomerDirectoryRepositoryInterface $directoryRepository,
    ) {}

    /**
     * Online order, completed and not sent yet. Box office and manual orders never are website purchases.
     * The buyer's consent is checked when sending, against the organizer settings.
     */
    public static function isEligible(OrderDomainObject $order): bool
    {
        return $order->getStatus() === OrderStatus::COMPLETED->name
            && $order->getMetaCapiSentAt() === null
            && ! $order->getIsManuallyCreated();
    }

    public static function hasConsent(OrderDomainObject $order): bool
    {
        return (($order->getTrackingContext() ?? [])['consent'] ?? false) === true;
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

        if ($settings === null
            || ! $settings->isMetaConversionsApiConfigured()
            || ! $settings->allowsMetaServerEvent(self::hasConsent($order))) {
            return false;
        }

        $eventsReceived = $this->client->sendEvents(
            pixelId: $settings->getEnabledFacebookPixelId(),
            accessToken: $settings->getMetaConversionsApiToken(),
            events: [$this->eventBuilder->build($order, $event, $this->findPhone($order))],
            testEventCode: $settings->getMetaTestEventCode(),
        );

        $this->orderRepository->updateFromArray($order->getId(), [
            OrderDomainObjectAbstract::META_CAPI_SENT_AT => Carbon::now(),
        ]);

        $this->logger->info('Meta Conversions API purchase sent', [
            'order_id' => $order->getId(),
            'consent' => self::hasConsent($order),
            'test_event_code' => $settings->getMetaTestEventCode() !== null,
            'events_received' => $eventsReceived,
        ]);

        return true;
    }

    private function findPhone(OrderDomainObject $order): ?string
    {
        try {
            return $this->directoryRepository
                ->findActiveAttendeesWithPhone($order->getEventId(), $order->getId())
                ->first(fn (SmsReminderAttendeeDTO $attendee) => $attendee->phone !== null && $attendee->phone !== '')
                ?->phone;
        } catch (Throwable $exception) {
            $this->logger->warning('Meta Conversions API purchase: phone lookup failed', [
                'order_id' => $order->getId(),
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
