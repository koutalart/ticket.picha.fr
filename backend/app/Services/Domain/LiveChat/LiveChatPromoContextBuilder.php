<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat;

use Carbon\Carbon;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\EventSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\ProductDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\PromoCodeDomainObjectAbstract;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\PromoCodeDomainObject;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\PromoCodeRepositoryInterface;

/**
 * The public promo code an organizer highlights on an event, with its real conditions, so the chat assistant can
 * quote it exactly. Only a code that still gives a discount today is exposed.
 */
class LiveChatPromoContextBuilder
{
    public const ALL_PRODUCTS = 'tous les billets';

    public function __construct(
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly PromoCodeRepositoryInterface $promoCodeRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly EventRepositoryInterface $eventRepository,
    ) {}

    /**
     * @param  list<int>  $eventIds
     * @return array<int, array{code: string, reduction: string, billets_concernes: string|list<string>, fin: string}>
     */
    public function forEvents(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $promos = [];
        $settings = $this->eventSettingsRepository->findWhereIn(EventSettingDomainObjectAbstract::EVENT_ID, $eventIds);

        /** @var EventSettingDomainObject $setting */
        foreach ($settings as $setting) {
            $code = strtolower(trim((string) $setting->getHighlightedPromoCode()));
            if ($code === '') {
                continue;
            }

            $promo = $this->describe($setting->getEventId(), $code);
            if ($promo !== null) {
                $promos[$setting->getEventId()] = $promo;
            }
        }

        return $promos;
    }

    /**
     * @return array{code: string, reduction: string, billets_concernes: string|list<string>, fin: string}|null
     */
    private function describe(int $eventId, string $code): ?array
    {
        /** @var PromoCodeDomainObject|null $promoCode */
        $promoCode = $this->promoCodeRepository->findFirstWhere([
            PromoCodeDomainObjectAbstract::EVENT_ID => $eventId,
            PromoCodeDomainObjectAbstract::CODE => $code,
        ]);
        if ($promoCode === null || ! $promoCode->isValid() || ! $promoCode->isDiscountCode()) {
            return null;
        }

        /** @var EventDomainObject|null $event */
        $event = $this->eventRepository->findFirstWhere(['id' => $eventId]);
        if ($event === null) {
            return null;
        }

        $products = $this->products($eventId, $promoCode);
        if ($products === []) {
            return null;
        }

        return [
            'code' => $promoCode->getCode(),
            'reduction' => self::discount($promoCode, $event->getCurrency()),
            'billets_concernes' => $products,
            'fin' => $promoCode->getExpiryDate() !== null
                ? Carbon::parse($promoCode->getExpiryDate(), 'UTC')
                    ->setTimezone($event->getTimezone() ?: 'UTC')
                    ->locale('fr')
                    ->isoFormat('dddd D MMMM YYYY [à] HH[h]mm')
                : 'pas de date de fin',
        ];
    }

    /**
     * @return string|list<string> every product of the code by name, or ALL_PRODUCTS; [] when none is left
     */
    private function products(int $eventId, PromoCodeDomainObject $promoCode): string|array
    {
        $ids = $promoCode->getApplicableProductIds();
        if (is_string($ids)) {
            $ids = json_decode($ids, true);
        }
        $ids = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : [])));
        if ($ids === []) {
            return self::ALL_PRODUCTS;
        }

        return $this->productRepository
            ->findWhereIn(ProductDomainObjectAbstract::ID, $ids, [ProductDomainObjectAbstract::EVENT_ID => $eventId])
            ->map(fn (ProductDomainObject $product) => $product->getTitle())
            ->values()
            ->all();
    }

    public static function discount(PromoCodeDomainObject $promoCode, string $currency): string
    {
        $amount = $promoCode->getDiscount();
        $number = fmod($amount, 1.0) === 0.0
            ? number_format($amount, 0, ',', ' ')
            : number_format($amount, 2, ',', ' ');

        if ($promoCode->isPercentageDiscount()) {
            return $number.' % de réduction sur le prix de chaque billet concerné';
        }

        $unit = strtoupper($currency) === 'EUR' ? 'euros' : strtoupper($currency);

        return $number.' '.$unit.' de réduction sur le prix de chaque billet concerné';
    }
}
