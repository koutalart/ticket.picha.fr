<?php

declare(strict_types=1);

namespace Tests\Unit\Ticket;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\OrganizerStatus;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Domain\Ticket\AttendeeTicketZplService;
use HiEvents\Services\Domain\Ticket\DTO\TicketDataDTO;
use HiEvents\Services\Domain\Ticket\Layout\TicketGraphicElement;
use HiEvents\Services\Domain\Ticket\Layout\TicketLayout;
use HiEvents\Services\Domain\Ticket\Layout\TicketTextElement;
use HiEvents\Services\Domain\Ticket\PngTicketRenderer;
use HiEvents\Services\Domain\Ticket\TicketDataFactory;
use HiEvents\Services\Domain\Ticket\TicketLayoutBuilder;
use HiEvents\Services\Domain\Ticket\ZplImageConverter;
use HiEvents\Services\Domain\Ticket\ZplTicketRenderer;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Collection;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class TicketFooterOrganizerDomainTest extends TestCase
{
    private const FOOTER_Y = 540;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('fr');
    }

    private function logoPng(int $width = 300, int $height = 120): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 20, 20, $width - 20, $height - 20, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function ticket(?string $footerDomain = null, ?string $footerLogo = null): TicketDataDTO
    {
        return new TicketDataDTO(
            publicId: 'A-SFMVW8P',
            eventTitle: 'Mayotte La 1ère',
            productTitle: 'Entrée simple',
            attendeeName: 'Jane Doe',
            eventDate: 'Sam. 7 nov. 2026',
            eventTime: '18h00',
            venue: 'Mamoudzou',
            sellerName: 'Kultur\'m',
            footerDomain: $footerDomain,
            footerLogo: $footerLogo,
        );
    }

    private function layout(TicketDataDTO $ticket): TicketLayout
    {
        return (new TicketLayoutBuilder(new ZplImageConverter))->build($ticket);
    }

    private function zpl(TicketDataDTO $ticket): string
    {
        return (new AttendeeTicketZplService(new TicketLayoutBuilder(new ZplImageConverter), new ZplTicketRenderer))->generate($ticket);
    }

    /**
     * @return list<object>
     */
    private function footerElements(TicketLayout $layout): array
    {
        return array_values(array_filter($layout->elements, fn ($element) => $element->y >= self::FOOTER_Y));
    }

    /**
     * @return list<string>
     */
    private function texts(array $elements): array
    {
        return array_values(array_map(
            fn (TicketTextElement $text) => $text->text,
            array_filter($elements, fn ($element) => $element instanceof TicketTextElement),
        ));
    }

    private function pichaLogoHex(): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) file_get_contents(base_path('resources/zpl/picha-logo.gfa'))));
    }

    public function test_default_footer_is_unchanged(): void
    {
        $footer = $this->footerElements($this->layout($this->ticket()));

        self::assertSame(['Plateforme de gestion d\'événements', 'ticket.picha.fr'], $this->texts($footer));
        $domainText = array_values(array_filter($footer, fn ($e) => $e instanceof TicketTextElement && $e->text === 'ticket.picha.fr'))[0];
        self::assertSame([334, 588, 41, 34, null], [$domainText->x, $domainText->y, $domainText->height, $domainText->width, $domainText->blockWidth]);

        $graphics = array_values(array_filter($footer, fn ($e) => $e instanceof TicketGraphicElement));
        self::assertSame([44, 549], [$graphics[0]->x, $graphics[0]->y]);
        self::assertSame($this->pichaLogoHex(), $graphics[0]->graphic->hex);

        $zpl = $this->zpl($this->ticket());
        self::assertStringContainsString('^FO334,588^A0N,41,34^FDticket.picha.fr^FS', $zpl);
        self::assertStringContainsString($this->pichaLogoHex(), $zpl);
    }

    public function test_footer_shows_organizer_domain_and_logo_when_enabled(): void
    {
        $plain = $this->zpl($this->ticket());
        $zpl = $this->zpl($this->ticket('mayottela1ere.fr', $this->logoPng()));

        self::assertStringContainsString('^FDmayottela1ere.fr^FS', $zpl);
        self::assertStringContainsString('^FDBilletterie officielle^FS', $zpl);
        self::assertStringNotContainsString('ticket.picha.fr', $zpl);
        self::assertStringNotContainsString($this->pichaLogoHex(), $zpl);
        self::assertStringContainsString('^BQN,2,9^FDQA,A-SFMVW8P^FS', $zpl);

        $withoutFooter = fn (string $value) => substr($value, 0, (int) strpos($value, '^FO36,540'));
        self::assertSame($withoutFooter($plain), $withoutFooter($zpl));

        $footer = $this->footerElements($this->layout($this->ticket('mayottela1ere.fr', $this->logoPng())));
        $logo = array_values(array_filter($footer, fn ($e) => $e instanceof TicketGraphicElement))[0];
        self::assertLessThanOrEqual(200, $logo->graphic->width);
        self::assertLessThanOrEqual(78, $logo->graphic->height);
        self::assertGreaterThanOrEqual(44, $logo->x);
        self::assertLessThanOrEqual(258, $logo->x + $logo->graphic->width);
    }

    public function test_footer_without_organizer_logo_prints_organizer_name(): void
    {
        $texts = $this->texts($this->footerElements($this->layout($this->ticket('mayottela1ere.fr'))));

        self::assertSame(['Kultur\'m', 'Billetterie officielle', 'mayottela1ere.fr'], $texts);
    }

    public function test_long_domain_is_printed_smaller(): void
    {
        $footer = $this->footerElements($this->layout($this->ticket('billetterie.mayottela1ere.fr')));
        $domain = array_values(array_filter($footer, fn ($e) => $e instanceof TicketTextElement && $e->text === 'billetterie.mayottela1ere.fr'))[0];

        self::assertLessThan(34, $domain->width);
    }

    public function test_png_renders_with_organizer_footer(): void
    {
        $plain = (new PngTicketRenderer)->render($this->layout($this->ticket()));
        $custom = (new PngTicketRenderer)->render($this->layout($this->ticket('mayottela1ere.fr', $this->logoPng())));

        self::assertStringStartsWith("\x89PNG", $custom);
        self::assertNotSame($plain, $custom);
    }

    private function factory(bool $enabled, ?string $customDomain, string $status = 'ACTIVE', array $imageTypes = ['ORGANIZER_LOGO', 'ORGANIZER_EMAIL_LOGO']): TicketDataFactory
    {
        $event = (new EventDomainObject)
            ->setId(14)
            ->setOrganizerId(8)
            ->setTitle('Mayotte La 1ère')
            ->setTimezone('Indian/Mayotte')
            ->setCurrency('EUR')
            ->setImages(new Collection);

        $images = new Collection(array_map(
            fn (string $type) => (new ImageDomainObject)->setId(1)->setType($type)->setDisk('local')->setPath($type),
            $imageTypes,
        ));
        $organizer = (new OrganizerDomainObject)
            ->setId(8)
            ->setName('Kultur\'m')
            ->setStatus($status)
            ->setCustomDomain($customDomain)
            ->setImages($images);

        $eventRepository = m::mock(EventRepositoryInterface::class);
        $eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $eventRepository->shouldReceive('findById')->with(14)->andReturn($event);

        $organizerRepository = m::mock(OrganizerRepositoryInterface::class);
        $organizerRepository->shouldReceive('loadRelation')->andReturnSelf();
        $organizerRepository->shouldReceive('findById')->with(8)->andReturn($organizer);

        $settingsRepository = m::mock(EventSettingsRepositoryInterface::class);
        $settingsRepository->shouldReceive('findFirstWhere')->andReturn(
            (new EventSettingDomainObject)->setTicketFooterUseOrganizerDomain($enabled),
        );

        $productRepository = m::mock(ProductRepositoryInterface::class);
        $productRepository->shouldReceive('findFirstWhere')->andReturn((new ProductDomainObject)->setTitle('Entrée simple'));

        $disk = m::mock(Filesystem::class);
        $disk->shouldReceive('get')->andReturnUsing(fn (string $path) => 'bytes:'.$path);
        $filesystem = m::mock(FilesystemManager::class);
        $filesystem->shouldReceive('disk')->andReturn($disk);

        return new TicketDataFactory(
            $eventRepository,
            $settingsRepository,
            $organizerRepository,
            $productRepository,
            $filesystem,
            m::mock(LoggerInterface::class)->shouldIgnoreMissing(),
        );
    }

    public function test_setting_off_keeps_platform_footer_even_with_custom_domain(): void
    {
        $ticket = $this->factory(false, 'mayottela1ere.fr')->forPreview(14);

        self::assertNull($ticket->footerDomain);
        self::assertNull($ticket->footerLogo);
    }

    public function test_setting_on_with_custom_domain_uses_domain_and_light_background_logo(): void
    {
        $ticket = $this->factory(true, 'https://www.MayotteLa1ere.fr/')->forPreview(14);

        self::assertSame('mayottela1ere.fr', $ticket->footerDomain);
        self::assertSame('bytes:ORGANIZER_EMAIL_LOGO', $ticket->footerLogo);
        self::assertSame('A-APERCU00', $ticket->publicId);
    }

    public function test_setting_on_falls_back_to_organizer_logo(): void
    {
        $ticket = $this->factory(true, 'mayottela1ere.fr', imageTypes: ['ORGANIZER_LOGO'])->forPreview(14);

        self::assertSame('bytes:ORGANIZER_LOGO', $ticket->footerLogo);
    }

    public function test_setting_on_without_custom_domain_is_unchanged(): void
    {
        $ticket = $this->factory(true, null)->forPreview(14);

        self::assertNull($ticket->footerDomain);
        self::assertNull($ticket->footerLogo);
        self::assertStringContainsString('ticket.picha.fr', $this->zpl($ticket));
    }

    public function test_setting_on_with_archived_organizer_is_unchanged(): void
    {
        $ticket = $this->factory(true, 'mayottela1ere.fr', OrganizerStatus::ARCHIVED->name)->forPreview(14);

        self::assertNull($ticket->footerDomain);
    }
}
