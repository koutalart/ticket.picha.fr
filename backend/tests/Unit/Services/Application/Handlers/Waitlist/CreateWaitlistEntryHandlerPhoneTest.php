<?php

namespace Tests\Unit\Services\Application\Handlers\Waitlist;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\WaitlistEntryDomainObject;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductPriceRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Waitlist\CreateWaitlistEntryHandler;
use HiEvents\Services\Application\Handlers\Waitlist\DTO\CreateWaitlistEntryDTO;
use HiEvents\Services\Domain\Sms\SmsAvailabilityService;
use HiEvents\Services\Domain\Waitlist\CreateWaitlistEntryService;
use Illuminate\Validation\ValidationException;
use Mockery as m;
use Tests\TestCase;

class CreateWaitlistEntryHandlerPhoneTest extends TestCase
{
    private function handler(bool $smsAvailable, CreateWaitlistEntryService $service): CreateWaitlistEntryHandler
    {
        $settings = m::mock(EventSettingsRepositoryInterface::class);
        $settings->shouldReceive('findFirstWhere')->andReturn(new EventSettingDomainObject);
        $prices = m::mock(ProductPriceRepositoryInterface::class);
        $prices->shouldReceive('findById')->andReturn((new ProductPriceDomainObject)->setId(30)->setProductId(27));
        $products = m::mock(ProductRepositoryInterface::class);
        $products->shouldReceive('findFirstWhere')->andReturn((new ProductDomainObject)->setId(27)->setEventId(14));
        $events = m::mock(EventRepositoryInterface::class);
        $events->shouldReceive('findFirst')->andReturn((new EventDomainObject)->setId(14)->setOrganizerId(8));
        $sms = m::mock(SmsAvailabilityService::class);
        $sms->shouldReceive('canSend')->andReturn($smsAvailable);

        return new CreateWaitlistEntryHandler($service, $settings, $prices, $products, $events, $sms);
    }

    private function dto(?string $phone): CreateWaitlistEntryDTO
    {
        return new CreateWaitlistEntryDTO(event_id: 14, product_price_id: 30, email: 'a@b.fr', first_name: 'Awa', phone: $phone);
    }

    public function test_phone_required_when_the_organizer_sends_sms(): void
    {
        $service = m::mock(CreateWaitlistEntryService::class);
        $service->shouldNotReceive('createEntry');

        $this->expectException(ValidationException::class);
        $this->handler(true, $service)->handle($this->dto(null));
    }

    public function test_phone_optional_without_sms_line(): void
    {
        $service = m::mock(CreateWaitlistEntryService::class);
        $service->shouldReceive('createEntry')->once()->andReturn(new WaitlistEntryDomainObject);

        $this->handler(false, $service)->handle($this->dto(null));
        $this->addToAssertionCount(1);
    }

    public function test_invalid_phone_rejected(): void
    {
        $service = m::mock(CreateWaitlistEntryService::class);
        $service->shouldNotReceive('createEntry');

        $this->expectException(ValidationException::class);
        $this->handler(false, $service)->handle($this->dto('12'));
    }
}
