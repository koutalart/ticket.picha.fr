<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Meta;

use Carbon\Carbon;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;

class MetaPurchaseEventBuilder
{
    public const EVENT_ID_PREFIX = 'order_';

    public function __construct(
        private readonly MetaUserDataHasher $hasher,
    ) {}

    public function build(OrderDomainObject $order, EventDomainObject $event, ?string $phone = null): array
    {
        $context = (array) ($order->getTrackingContext() ?? []);
        $consent = ($context['consent'] ?? false) === true;

        $customData = array_filter([
            'currency' => strtoupper($order->getCurrency()),
            'value' => round($order->getTotalGross(), 2),
            'content_type' => 'product',
            'content_ids' => [(string) $event->getId()],
            'content_name' => $event->getTitle(),
            'order_id' => $order->getShortId(),
            'num_items' => $order->getOrderItems()
                ?->sum(fn (OrderItemDomainObject $item) => $item->getQuantity()),
        ], static fn ($value) => $value !== null);

        return array_filter([
            'event_name' => 'Purchase',
            'event_time' => Carbon::now()->getTimestamp(),
            'event_id' => self::EVENT_ID_PREFIX.$order->getShortId(),
            'action_source' => 'website',
            'event_source_url' => $context['event_source_url'] ?? $event->getEventUrl(),
            'user_data' => $this->buildUserData($order, $consent ? $context : [], $phone),
            'custom_data' => $customData,
        ], static fn ($value) => $value !== null);
    }

    private function buildUserData(OrderDomainObject $order, array $context, ?string $phone): array
    {
        return array_filter([
            'em' => $this->wrap($this->hasher->email($order->getEmail())),
            'ph' => $this->wrap($this->hasher->phone($phone)),
            'fn' => $this->wrap($this->hasher->name($order->getFirstName())),
            'ln' => $this->wrap($this->hasher->name($order->getLastName())),
            'country' => $this->wrap($this->hasher->countryCode($order->getAddressDTO()?->country)),
            'client_ip_address' => $context['client_ip_address'] ?? null,
            'client_user_agent' => $context['client_user_agent'] ?? null,
            'fbp' => $context['fbp'] ?? null,
            'fbc' => MetaClickId::fbc($context['fbc'] ?? null, $context['fbclid'] ?? null),        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function wrap(?string $hash): ?array
    {
        return $hash === null ? null : [$hash];
    }
}
