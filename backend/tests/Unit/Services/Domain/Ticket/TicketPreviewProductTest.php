<?php

namespace Tests\Unit\Services\Domain\Ticket;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Domain\Ticket\TicketDataFactory;
use Illuminate\Filesystem\FilesystemManager;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class TicketPreviewProductTest extends TestCase
{
    public function test_preview_uses_a_ticket_and_never_an_option_without_ticket(): void
    {
        $event = (new EventDomainObject)
            ->setId(7)
            ->setTitle('Halloween')
            ->setStartDate('2026-10-31 16:00:00')
            ->setTimezone('Indian/Mayotte')
            ->setCurrency('EUR');

        $eventRepository = m::mock(EventRepositoryInterface::class);
        $eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $eventRepository->shouldReceive('findById')->with(7)->andReturn($event);

        $eventSettingsRepository = m::mock(EventSettingsRepositoryInterface::class);
        $eventSettingsRepository->shouldReceive('findFirstWhere')->andReturnNull();

        $productRepository = m::mock(ProductRepositoryInterface::class);
        $productRepository->shouldReceive('findFirstWhere')
            ->once()
            ->with(['event_id' => 7, 'product_type' => 'TICKET'])
            ->andReturn((new ProductDomainObject)->setTitle('Vente flash'));

        $factory = new TicketDataFactory(
            $eventRepository,
            $eventSettingsRepository,
            m::mock(OrganizerRepositoryInterface::class),
            $productRepository,
            m::mock(FilesystemManager::class),
            m::mock(LoggerInterface::class)->shouldIgnoreMissing(),
        );

        $this->assertSame('Vente flash', $factory->forPreview(7)->productTitle);
    }
}
