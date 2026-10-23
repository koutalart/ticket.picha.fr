<?php

namespace Tests\Unit\Services\Domain\Mail;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Services\Domain\Mail\EmailLogoResolver;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

class EmailLogoResolverTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private ImageRepositoryInterface|MockInterface $imageRepository;

    private LoggerInterface|MockInterface $logger;

    private EmailLogoResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.cdn_url_override' => 'https://cdn.test']);

        $this->imageRepository = Mockery::mock(ImageRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->resolver = new EmailLogoResolver($this->imageRepository, $this->logger);
    }

    private function image(string $type, string $path): ImageDomainObject
    {
        return (new ImageDomainObject)->setType($type)->setPath($path);
    }

    private function givenImages(array $eventImages, array $organizerImages): void
    {
        $this->imageRepository->shouldReceive('findWhere')
            ->with(['entity_id' => 14, 'entity_type' => EventDomainObject::class])
            ->andReturn(new Collection($eventImages));
        $this->imageRepository->shouldReceive('findWhere')
            ->with(['entity_id' => 8, 'entity_type' => OrganizerDomainObject::class])
            ->andReturn(new Collection($organizerImages));
    }

    public function test_event_email_logo_comes_first(): void
    {
        $this->givenImages(
            [$this->image('EVENT_COVER', 'event_cover/cover.webp'), $this->image('EVENT_EMAIL_LOGO', 'event_email_logo/kulturm.png')],
            [$this->image('ORGANIZER_EMAIL_LOGO', 'organizer_email_logo/m1.png')],
        );

        $logo = $this->resolver->resolveForEvent(14, 8, 'Mayotte La 1ère');

        $this->assertSame('https://cdn.test/event_email_logo/kulturm.png', $logo?->url);
        $this->assertSame('Mayotte La 1ère', $logo->alt);
    }

    public function test_organizer_email_logo_is_used_when_the_event_has_none(): void
    {
        $this->givenImages(
            [$this->image('EVENT_COVER', 'event_cover/cover.webp')],
            [$this->image('ORGANIZER_LOGO', 'organizer_logo/blanc.png'), $this->image('ORGANIZER_EMAIL_LOGO', 'organizer_email_logo/m1.png')],
        );

        $this->assertSame('https://cdn.test/organizer_email_logo/m1.png', $this->resolver->resolveForEvent(14, 8)?->url);
    }

    public function test_organizer_logo_is_the_last_organizer_fallback(): void
    {
        $this->givenImages([], [$this->image('ORGANIZER_COVER', 'organizer_cover/c.webp'), $this->image('ORGANIZER_LOGO', 'organizer_logo/blanc.png')]);

        $this->assertSame('https://cdn.test/organizer_logo/blanc.png', $this->resolver->resolveForEvent(14, 8)?->url);
    }

    public function test_null_means_platform_logo(): void
    {
        $this->givenImages([], [$this->image('ORGANIZER_COVER', 'organizer_cover/c.webp')]);

        $this->assertNull($this->resolver->resolveForEvent(14, 8));
    }

    public function test_missing_ids_do_not_query(): void
    {
        $this->imageRepository->shouldNotReceive('findWhere');

        $this->assertNull($this->resolver->resolveForEvent(null, null));
    }

    public function test_blank_alt_is_dropped(): void
    {
        $this->givenImages([$this->image('EVENT_EMAIL_LOGO', 'event_email_logo/kulturm.png')], []);

        $this->assertNull($this->resolver->resolveForEvent(14, 8, '   ')?->alt);
    }

    public function test_database_error_keeps_the_platform_logo(): void
    {
        $this->imageRepository->shouldReceive('findWhere')->andThrow(new RuntimeException('down'));
        $this->logger->shouldReceive('warning')->once();

        $this->assertNull($this->resolver->resolveForEvent(14, 8));
    }
}
