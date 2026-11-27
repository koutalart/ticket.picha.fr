<?php

namespace Tests\Unit\Services\Domain\Event\Presentation;

use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductCategoryDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\QuestionDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductCategoryRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductPriceRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use HiEvents\Services\Domain\Question\BuyerPhoneQuestionService;
use HiEvents\Services\Domain\Event\Presentation\EventPresentationTemplateService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class EventPresentationTemplateServiceTest extends TestCase
{
    private MockInterface $settings;

    private MockInterface $events;

    private MockInterface $images;

    private MockInterface $questions;

    private MockInterface $products;

    private MockInterface $prices;

    private MockInterface $categories;

    private EventPresentationTemplateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = Mockery::mock(EventSettingsRepositoryInterface::class);
        $this->events = Mockery::mock(EventRepositoryInterface::class);
        $this->images = Mockery::mock(ImageRepositoryInterface::class);
        $this->questions = Mockery::mock(QuestionRepositoryInterface::class);
        $this->products = Mockery::mock(ProductRepositoryInterface::class);
        $this->prices = Mockery::mock(ProductPriceRepositoryInterface::class);
        $this->categories = Mockery::mock(ProductCategoryRepositoryInterface::class);
        $this->service = new EventPresentationTemplateService(
            $this->settings,
            $this->events,
            $this->images,
            new BuyerPhoneQuestionService($this->questions),
            $this->products,
            $this->prices,
            $this->categories,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_applies_the_halloween_layout_with_the_poster_colour_and_the_organizer_logo(): void
    {
        $this->settings->shouldReceive('findFirstWhere')->once()->with(['event_id' => 23])
            ->andReturn((new EventSettingDomainObject)->setId(400));
        $this->images->shouldReceive('findFirstWhere')->once()->with([
            'entity_id' => 20,
            'entity_type' => OrganizerDomainObject::class,
            'type' => 'ORGANIZER_LOGO',
        ])->andReturn((new ImageDomainObject)->setId(230));

        $saved = null;
        $this->settings->shouldReceive('updateFromArray')->once()->with(400, Mockery::on(function (array $attributes) use (&$saved) {
            $saved = $attributes;

            return true;
        }));
        $this->events->shouldReceive('updateWhere')->once()->with(['category' => 'MUSIC'], ['id' => 23]);
        $this->questions->shouldReceive('findFirstWhere')->once()
            ->with(['event_id' => 23, 'type' => 'PHONE', 'belongs_to' => 'ORDER'])->andReturnNull();
        $this->questions->shouldReceive('create')->once()->with(Mockery::on(fn (array $question) => $question['event_id'] === 23
            && $question['title'] === 'Numéro de téléphone'
            && $question['required'] === true
            && $question['type'] === 'PHONE'
            && $question['belongs_to'] === 'ORDER'));

        $template = (new ProductDomainObject)->setId(13)->setEventId(7)->setProductCategoryId(3)
            ->setTitle('Option sérénité / commande')->setIsRefundProtection(true)->setUnlockAfterProductId(10)
            ->setSaleEndDate('2026-10-31 23:59:00')->setSalesVolume(51.94);
        $this->products->shouldReceive('findFirstWhere')->with(['event_id' => 23, 'is_refund_protection' => true])->andReturnNull();
        $this->products->shouldReceive('findFirstWhere')->with(['event_id' => 7, 'is_refund_protection' => true])->andReturn($template);
        $this->categories->shouldReceive('findFirstWhere')->with(['event_id' => 23])->andReturn((new ProductCategoryDomainObject)->setId(33));
        $copied = null;
        $this->products->shouldReceive('create')->once()->with(Mockery::on(function (array $product) use (&$copied) {
            $copied = $product;

            return true;
        }))->andReturn((new ProductDomainObject)->setId(56));
        $this->prices->shouldReceive('findWhere')->with(['product_id' => 13])->andReturn(collect([
            (new ProductPriceDomainObject)->setId(14)->setProductId(13)->setPrice(0.49)->setQuantitySold(106)->setInitialQuantityAvailable(null),
        ]));
        $this->prices->shouldReceive('create')->once()->with(Mockery::on(fn (array $price) => $price['product_id'] === 56
            && $price['price'] === 0.49
            && $price['quantity_sold'] === 0
            && ! array_key_exists('id', $price)));

        $this->service->apply(23, 20, '#1f8fffff');

        $this->assertSame(23, $copied['event_id']);
        $this->assertSame(33, $copied['product_category_id']);
        $this->assertTrue($copied['is_refund_protection']);
        $this->assertSame('Option sérénité / commande', $copied['title']);
        $this->assertNull($copied['unlock_after_product_id']);
        $this->assertNull($copied['sale_end_date']);
        $this->assertSame(0, $copied['sales_volume']);
        $this->assertArrayNotHasKey('id', $copied);

        $this->assertSame('Acheter', $saved['continue_button_text']);
        $this->assertTrue($saved['hide_getting_started_page']);
        $this->assertSame('MIRROR_COVER_IMAGE', $saved['homepage_background_type']);
        $this->assertSame('#1f8fffff', $saved['homepage_primary_color']);
        $this->assertSame('CARD', $saved['homepage_theme_settings']['hero_layout']);
        $this->assertSame('#1f8fffff', $saved['homepage_theme_settings']['accent']);
        $this->assertSame('Outfit', $saved['homepage_theme_settings']['font_family']);
        $this->assertTrue($saved['ticket_design_settings']['enabled']);
        $this->assertSame(230, $saved['ticket_design_settings']['logo_image_id']);
        $this->assertSame('#1f8fffff', $saved['ticket_design_settings']['accent_color']);
        $this->assertTrue($saved['homepage_footer_settings']['newsletter_enabled']);
    }

    public function test_without_poster_colour_it_uses_the_default_accent_and_no_logo(): void
    {
        $this->settings->shouldReceive('findFirstWhere')->andReturn((new EventSettingDomainObject)->setId(401));
        $this->images->shouldReceive('findFirstWhere')->andReturnNull();
        $saved = null;
        $this->settings->shouldReceive('updateFromArray')->once()->with(401, Mockery::on(function (array $attributes) use (&$saved) {
            $saved = $attributes;

            return true;
        }));
        $this->events->shouldReceive('updateWhere')->once();
        $this->questions->shouldReceive('findFirstWhere')->andReturn((new QuestionDomainObject)->setId(54)->setRequired(true));
        $this->questions->shouldNotReceive('create');
        $this->products->shouldReceive('findFirstWhere')->with(['event_id' => 24, 'is_refund_protection' => true])
            ->andReturn((new ProductDomainObject)->setId(57));
        $this->products->shouldNotReceive('create');

        $this->service->apply(24, 20);

        $this->assertSame(EventPresentationTemplateService::DEFAULT_ACCENT, $saved['homepage_primary_color']);
        $this->assertNull($saved['ticket_design_settings']['logo_image_id']);
    }

    public function test_an_event_without_settings_is_refused(): void
    {
        $this->settings->shouldReceive('findFirstWhere')->andReturnNull();
        $this->settings->shouldNotReceive('updateFromArray');

        $this->expectException(ResourceNotFoundException::class);

        $this->service->apply(99, 20);
    }
}
