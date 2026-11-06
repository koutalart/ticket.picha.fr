<?php

namespace Tests\Unit\DomainObjects;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\ProductCategoryDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Resources\Event\EventSettingsResourcePublic;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Tests\TestCase;

class EventManualSeoTest extends TestCase
{
    public function test_manual_seo_written_after_the_last_change_applies(): void
    {
        $event = $this->event(seoUpdatedAt: '2026-10-22 10:00:00', eventUpdatedAt: '2026-10-21 10:00:00', priceUpdatedAt: '2026-10-20 10:00:00');

        $this->assertFalse($event->isManualSeoOutdated());
        $this->assertSame('Entrée gratuite sur inscription', $this->publicSettings($event)['seo_description']);
    }

    public function test_manual_seo_is_ignored_once_the_event_changed(): void
    {
        $event = $this->event(seoUpdatedAt: '2026-10-22 10:00:00', eventUpdatedAt: '2026-11-05 10:00:00', priceUpdatedAt: '2026-10-20 10:00:00');

        $this->assertTrue($event->isManualSeoOutdated());
        $settings = $this->publicSettings($event);
        $this->assertNull($settings['seo_title']);
        $this->assertNull($settings['seo_description']);
        $this->assertNull($settings['seo_keywords']);
    }

    public function test_manual_seo_is_ignored_once_a_ticket_price_changed(): void
    {
        $event = $this->event(seoUpdatedAt: '2026-10-22 10:00:00', eventUpdatedAt: '2026-10-21 10:00:00', priceUpdatedAt: '2026-11-05 10:00:00');

        $this->assertTrue($event->isManualSeoOutdated());
    }

    public function test_event_without_manual_seo_is_never_outdated(): void
    {
        $event = $this->event(seoUpdatedAt: null, eventUpdatedAt: '2026-11-05 10:00:00', priceUpdatedAt: '2026-11-05 10:00:00', withSeo: false);

        $this->assertFalse($event->isManualSeoOutdated());
    }

    private function event(?string $seoUpdatedAt, string $eventUpdatedAt, string $priceUpdatedAt, bool $withSeo = true): EventDomainObject
    {
        $settings = (new EventSettingDomainObject())->setSeoUpdatedAt($seoUpdatedAt);
        if ($withSeo) {
            $settings->setSeoTitle('Concert des générations')
                ->setSeoDescription('Entrée gratuite sur inscription')
                ->setSeoKeywords('concert, gratuit');
        }

        $product = (new ProductDomainObject())->setId(1)->setUpdatedAt('2026-10-20 10:00:00');
        $product->setProductPrices(new Collection([(new ProductPriceDomainObject())->setId(1)->setUpdatedAt($priceUpdatedAt)]));
        $category = (new ProductCategoryDomainObject())->setId(1);
        $category->setProducts(new Collection([$product]));

        $event = (new EventDomainObject())->setId(14)->setUpdatedAt($eventUpdatedAt);
        $event->setEventSettings($settings);
        $event->setProductCategories(new Collection([$category]));

        return $event;
    }

    private function publicSettings(EventDomainObject $event): array
    {
        return (new EventSettingsResourcePublic($event->getEventSettings(), false, $event->isManualSeoOutdated()))->toArray(new Request());
    }
}
