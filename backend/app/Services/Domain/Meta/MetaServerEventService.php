<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Meta;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\OrganizerSettingDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\Exceptions\MetaConversionsApiException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Services\Domain\Meta\DTO\MetaServerEventDTO;
use HiEvents\Services\Infrastructure\Meta\MetaConversionsApiClient;
use Psr\Log\LoggerInterface;

/**
 * Sends the page and checkout events (PageView, ViewContent, InitiateCheckout) to the Conversions API.
 * Each one carries the event_id of the matching pixel event so Meta keeps only one of the two.
 */
class MetaServerEventService
{
    public const PAGE_VIEW = 'PageView';

    public const VIEW_CONTENT = 'ViewContent';

    public const INITIATE_CHECKOUT = 'InitiateCheckout';

    public const EVENT_NAMES = [self::PAGE_VIEW, self::VIEW_CONTENT, self::INITIATE_CHECKOUT];

    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrganizerSettingsRepositoryInterface $organizerSettingsRepository,
        private readonly MetaConversionsApiClient $client,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The organizer settings when this event may be sent, null otherwise.
     */
    public function isAllowed(int $eventId, bool $consent): bool
    {
        return $this->resolve($eventId, $consent) !== null;
    }

    /**
     * @throws MetaConversionsApiException
     */
    public function send(MetaServerEventDTO $dto): bool
    {
        if (! in_array($dto->eventName, self::EVENT_NAMES, true)) {
            return false;
        }

        $resolved = $this->resolve($dto->eventId, $dto->consent);
        if ($resolved === null) {
            return false;
        }

        [$event, $settings] = $resolved;

        $eventsReceived = $this->client->sendEvents(
            pixelId: $settings->getEnabledFacebookPixelId(),
            accessToken: $settings->getMetaConversionsApiToken(),
            events: [$this->build($dto, $event->getEventUrl())],
            testEventCode: $settings->getMetaTestEventCode(),
        );

        $this->logger->info('Meta Conversions API event sent', [
            'event_name' => $dto->eventName,
            'event_id' => $dto->eventId,
            'consent' => $dto->consent,
            'test_event_code' => $settings->getMetaTestEventCode() !== null,
            'events_received' => $eventsReceived,
        ]);

        return true;
    }

    public function build(MetaServerEventDTO $dto, ?string $fallbackUrl = null): array
    {
        $customData = array_filter([
            'currency' => $dto->currency !== null ? strtoupper($dto->currency) : null,
            'value' => $dto->value !== null ? round($dto->value, 2) : null,
            'content_type' => 'product',
            'content_ids' => [(string) $dto->eventId],
            'num_items' => $dto->numItems,
        ], static fn ($value) => $value !== null);

        $userData = array_filter([
            'client_ip_address' => $dto->clientIpAddress,
            'client_user_agent' => $dto->clientUserAgent,
            'fbp' => $dto->consent ? $dto->fbp : null,
            'fbc' => $dto->consent ? $dto->fbc : null,
        ], static fn ($value) => $value !== null && $value !== '');

        return array_filter([
            'event_name' => $dto->eventName,
            'event_time' => $dto->eventTime,
            'event_id' => $dto->metaEventId,
            'action_source' => 'website',
            'event_source_url' => $dto->eventSourceUrl ?? $fallbackUrl,
            'user_data' => $userData,
            'custom_data' => $dto->eventName === self::PAGE_VIEW ? null : $customData,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @return array{0: EventDomainObject, 1: OrganizerSettingDomainObject}|null
     */
    private function resolve(int $eventId, bool $consent): ?array
    {
        /** @var EventDomainObject|null $event */
        $event = $this->eventRepository->findFirst($eventId);
        if ($event === null) {
            return null;
        }

        /** @var OrganizerSettingDomainObject|null $settings */
        $settings = $this->organizerSettingsRepository->findFirstWhere([
            OrganizerSettingDomainObjectAbstract::ORGANIZER_ID => $event->getOrganizerId(),
        ]);

        if ($settings === null || ! $settings->isMetaConversionsApiConfigured() || ! $settings->allowsMetaServerEvent($consent)) {
            return null;
        }

        return [$event, $settings];
    }
}
