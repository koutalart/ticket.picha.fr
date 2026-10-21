<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use Carbon\Carbon;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\ProductCategoryDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\Product\ProductFilterService;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantEventInfoDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantTicketInfoDTO;
use Illuminate\Support\Collection;

/**
 * What a buyer sees on the public pages of the organizer's upcoming events: dates, venue, tickets on sale and their price.
 */
class SmsAssistantEventCatalogService
{
    private const MAX_EVENTS = 5;

    private const LOOKBACK_DAYS = 7;

    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly ProductFilterService $productFilterService,
    ) {}

    /**
     * @return Collection<int, SmsAssistantEventInfoDTO>
     */
    public function getUpcomingEvents(int $organizerId): Collection
    {
        $events = $this->eventRepository
            ->loadRelation(new Relationship(ProductCategoryDomainObject::class, [
                new Relationship(ProductDomainObject::class,
                    nested: [
                        new Relationship(ProductPriceDomainObject::class),
                        new Relationship(TaxAndFeesDomainObject::class),
                    ],
                    orderAndDirections: [new OrderAndDirection('order', 'asc')],
                ),
            ]))
            ->loadRelation(new Relationship(EventSettingDomainObject::class))
            ->findWhere(
                where: [
                    EventDomainObjectAbstract::ORGANIZER_ID => $organizerId,
                    EventDomainObjectAbstract::STATUS => EventStatus::LIVE->name,
                    [EventDomainObjectAbstract::START_DATE, '>=', now()->subDays(self::LOOKBACK_DAYS)->toDateTimeString()],
                ],
                orderAndDirections: [new OrderAndDirection(EventDomainObjectAbstract::START_DATE, 'asc')],
            );

        return $events
            ->reject(fn (EventDomainObject $event) => $event->isEventInPast())
            ->take(self::MAX_EVENTS)
            ->map(fn (EventDomainObject $event) => $this->describe($event))
            ->values();
    }

    private function describe(EventDomainObject $event): SmsAssistantEventInfoDTO
    {
        $timezone = $event->getTimezone() ?: 'UTC';
        $categories = $event->getProductCategories() ?? collect();
        $tickets = [];

        if ($categories->isNotEmpty()) {
            $visible = $this->productFilterService->filter($categories);

            foreach ($visible as $category) {
                foreach ($category->getProducts() ?? [] as $product) {
                    foreach ($product->getProductPrices() ?? [] as $price) {
                        $tickets[] = $this->describeTicket($event, $product, $price);
                    }
                }
            }
        }

        return new SmsAssistantEventInfoDTO(
            title: $event->getTitle(),
            startsAt: $this->localDate($event->getStartDate(), $timezone),
            endsAt: $this->localDate($event->getEndDate(), $timezone),
            venue: $this->venue($event->getEventSettings()),
            url: $event->getEventUrl(),
            tickets: $tickets,
        );
    }

    private function describeTicket(
        EventDomainObject $event,
        ProductDomainObject $product,
        ProductPriceDomainObject $price,
    ): SmsAssistantTicketInfoDTO {
        $name = $product->getTitle();
        if ($product->isTieredType() && $price->getLabel()) {
            $name .= ' ('.$price->getLabel().')';
        }

        $availability = match (true) {
            (bool) $price->isAvailable() => SmsAssistantTicketInfoDTO::ON_SALE,
            $price->isSoldOut() || $product->isSoldOut() => SmsAssistantTicketInfoDTO::SOLD_OUT,
            default => SmsAssistantTicketInfoDTO::NOT_ON_SALE,
        };

        return new SmsAssistantTicketInfoDTO(
            name: $name,
            price: match (true) {
                $product->isFreeType() || $price->isFree() => 0.0,
                $product->isDonationType() => null,
                default => $price->getPriceIncludingTaxAndServiceFee(),
            },
            currency: $event->getCurrency(),
            availability: $availability,
        );
    }

    private function localDate(?string $date, string $timezone): ?string
    {
        if ($date === null) {
            return null;
        }

        return Carbon::parse($date, 'UTC')
            ->setTimezone($timezone)
            ->locale('fr')
            ->isoFormat('dddd D MMMM YYYY [à] HH[h]mm');
    }

    private function venue(?EventSettingDomainObject $settings): ?string
    {
        if ($settings === null) {
            return null;
        }

        if ($settings->getIsOnlineEvent()) {
            return 'En ligne';
        }

        $address = trim($settings->getAddressString());

        return $address !== '' ? $address : null;
    }
}
