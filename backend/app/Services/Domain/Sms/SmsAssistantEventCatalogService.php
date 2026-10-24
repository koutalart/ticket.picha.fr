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

    private const MAX_TEXT_LENGTH = 1500;

    private const MAX_PAGE_ITEMS = 15;

    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly ProductFilterService $productFilterService,
    ) {}

    /**
     * @param  bool  $withDetails  Also describe the event, its tickets and its dedicated pages (FAQ, practical info, artists)
     * @return Collection<int, SmsAssistantEventInfoDTO>
     */
    public function getUpcomingEvents(int $organizerId, bool $withDetails = false): Collection
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
            ->map(fn (EventDomainObject $event) => $this->describe($event, $withDetails))
            ->values();
    }

    private function describe(EventDomainObject $event, bool $withDetails): SmsAssistantEventInfoDTO
    {
        $timezone = $event->getTimezone() ?: 'UTC';
        $categories = $event->getProductCategories() ?? collect();
        $tickets = [];

        if ($categories->isNotEmpty()) {
            $visible = $this->productFilterService->filter($categories);

            foreach ($visible as $category) {
                foreach ($category->getProducts() ?? [] as $product) {
                    foreach ($product->getProductPrices() ?? [] as $price) {
                        $tickets[] = $this->describeTicket($event, $product, $price, $withDetails);
                    }
                }
            }
        }

        $settings = $event->getEventSettings();
        $pages = $withDetails ? $this->pages($settings) : [];

        return new SmsAssistantEventInfoDTO(
            title: $event->getTitle(),
            startsAt: $this->localDate($event->getStartDate(), $timezone),
            endsAt: $this->localDate($event->getEndDate(), $timezone),
            venue: $this->venue($settings),
            url: $event->getEventUrl(),
            tickets: $tickets,
            id: $withDetails ? $event->getId() : null,
            description: $withDetails ? self::plainText($event->getDescription()) : null,
            feesAddedAtCheckout: $withDetails && $settings !== null ? $settings->getPassPlatformFeeToBuyer() : null,
            practicalInfo: $pages['practical_info'] ?? [],
            faq: $pages['faq'] ?? [],
            artists: $pages['artists'] ?? [],
        );
    }

    /**
     * @return array{practical_info?: array, faq?: array, artists?: string[]}
     */
    private function pages(?EventSettingDomainObject $settings): array
    {
        $pages = $settings?->getEventPages();
        if (is_string($pages)) {
            $pages = json_decode($pages, true);
        }
        if (! is_array($pages)) {
            return [];
        }

        $practicalInfo = collect($pages['practical_info'] ?? [])
            ->filter(fn ($section) => is_array($section) && self::plainText($section['title'] ?? null) !== null)
            ->map(fn (array $section) => [
                'titre' => self::plainText($section['title']),
                'contenu' => self::plainText($section['content'] ?? null),
            ]);

        $faq = collect($pages['faq'] ?? [])
            ->filter(fn ($item) => is_array($item)
                && self::plainText($item['question'] ?? null) !== null
                && self::plainText($item['answer'] ?? null) !== null)
            ->map(fn (array $item) => [
                'question' => self::plainText($item['question']),
                'reponse' => self::plainText($item['answer']),
            ]);

        $artists = collect($pages['artists'] ?? [])
            ->map(fn ($artist) => is_array($artist) ? self::plainText($artist['name'] ?? null) : null)
            ->filter();

        return [
            'practical_info' => $practicalInfo->take(self::MAX_PAGE_ITEMS)->values()->all(),
            'faq' => $faq->take(self::MAX_PAGE_ITEMS)->values()->all(),
            'artists' => $artists->take(self::MAX_PAGE_ITEMS)->values()->all(),
        ];
    }

    private static function plainText(mixed $html): ?string
    {
        if (! is_string($html)) {
            return null;
        }

        $text = preg_replace('~<\s*(br|/p|/li|/h[1-6])[^>]*>~i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace("/[ \t]+/u", ' ', preg_replace("/\n\s*\n+/u", "\n", $text) ?? $text) ?? $text);

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > self::MAX_TEXT_LENGTH ? rtrim(mb_substr($text, 0, self::MAX_TEXT_LENGTH)).'...' : $text;
    }

    private function describeTicket(
        EventDomainObject $event,
        ProductDomainObject $product,
        ProductPriceDomainObject $price,
        bool $withDetails = false,
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
                default => $price->getPrice(),
            },
            currency: $event->getCurrency(),
            availability: $availability,
            description: $withDetails ? self::plainText($product->getDescription()) : null,
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
