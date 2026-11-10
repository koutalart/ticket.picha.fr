<?php

declare(strict_types=1);

namespace Tests\Unit\Ticket;

use HiEvents\Services\Domain\Ticket\AttendeeTicketZplService;
use HiEvents\Services\Domain\Ticket\DTO\TicketCardDesignDTO;
use HiEvents\Services\Domain\Ticket\DTO\TicketDataDTO;
use HiEvents\Services\Domain\Ticket\PngTicketRenderer;
use HiEvents\Services\Domain\Ticket\TicketCardRenderer;
use HiEvents\Services\Domain\Ticket\TicketImageService;
use HiEvents\Services\Domain\Ticket\TicketLayoutBuilder;
use HiEvents\Services\Domain\Ticket\ZplImageConverter;
use HiEvents\Services\Domain\Ticket\ZplTicketRenderer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickDraw;
use ImagickPixel;
use Psr\Log\NullLogger;
use Tests\TestCase;

class TicketCardRendererTest extends TestCase
{
    private const DISK = 'ticket-card-test';

    private const FRAME = ['x' => 353, 'y' => 1572, 'width' => 895, 'height' => 237];

    private string $backgroundPath;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(self::DISK);
        $this->backgroundPath = 'ticket-cards/event-14/'.uniqid('', true).'-fond.png';
        Storage::disk(self::DISK)->put($this->backgroundPath, $this->background());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/ticket-card-cache'));

        parent::tearDown();
    }

    private function background(): string
    {
        $image = new Imagick;
        $image->newImage(1600, 2000, new ImagickPixel('#0b2340'));
        $draw = new ImagickDraw;
        $draw->setFillColor(new ImagickPixel('white'));
        $draw->roundRectangle(
            self::FRAME['x'],
            self::FRAME['y'],
            self::FRAME['x'] + self::FRAME['width'] - 1,
            self::FRAME['y'] + self::FRAME['height'] - 1,
            12,
            12,
        );
        $image->drawImage($draw);
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }

    private function design(?string $backgroundPath = null): TicketCardDesignDTO
    {
        app()->setLocale('fr');

        return TicketCardDesignDTO::fromSettings([
            'disk' => self::DISK,
            'background_path' => $backgroundPath ?? $this->backgroundPath,
            'guests' => 2,
            'dress_code' => 'bleu nuit, champagne et doré',
        ], 'local');
    }

    private function ticket(?TicketCardDesignDTO $design): TicketDataDTO
    {
        return new TicketDataDTO(
            publicId: 'A-K7QZ2M4',
            eventTitle: 'Concert des Générations',
            productTitle: 'Invitation',
            attendeeName: 'Josué Koutala',
            eventDate: 'Sam. 14 nov. 2026',
            eventTime: '18h30',
            venue: 'Mayotte la 1ère, Hauts Vallons',
            sellerName: 'Mayotte La 1ère',
            cardDesign: $design,
        );
    }

    private function imageService(): TicketImageService
    {
        return new TicketImageService(
            new TicketLayoutBuilder(new ZplImageConverter),
            new PngTicketRenderer,
            new TicketCardRenderer(app('filesystem'), new NullLogger),
        );
    }

    public function test_card_is_rendered_on_the_full_background_picture(): void
    {
        $image = $this->imageService()->image($this->ticket($this->design()));

        self::assertTrue($image->isCard);
        self::assertSame('image/jpeg', $image->mimeType);
        self::assertSame(1600, $image->width);
        self::assertSame(2000, $image->height);

        $rendered = new Imagick;
        $rendered->readImageBlob($image->content);
        self::assertSame(1600, $rendered->getImageWidth());
        self::assertSame(2000, $rendered->getImageHeight());

        $qrCorner = $rendered->getImagePixelColor(self::FRAME['x'] + 24 + 8 + 5, self::FRAME['y'] + 24 + 5)->getColor();
        self::assertLessThan(60, max($qrCorner['r'], $qrCorner['g'], $qrCorner['b']), 'the QR finder pattern is dark navy');

        $outside = $rendered->getImagePixelColor(100, 100)->getColor();
        self::assertLessThan(80, $outside['r'], 'the background picture is kept');
    }

    public function test_frame_is_detected_in_the_lower_half_of_the_picture(): void
    {
        $image = new Imagick;
        $image->readImageBlob($this->background());

        $frame = (new TicketCardRenderer(app('filesystem'), new NullLogger))->detectFrame($image);

        self::assertSame(self::FRAME['y'], $frame['y']);
        self::assertSame(self::FRAME['height'], $frame['height']);
        self::assertSame(self::FRAME['x'], $frame['x']);
        self::assertSame(self::FRAME['width'], $frame['width']);
    }

    public function test_missing_background_falls_back_to_the_regular_ticket(): void
    {
        $image = $this->imageService()->image($this->ticket($this->design('ticket-cards/missing.png')));

        self::assertFalse($image->isCard);
        self::assertSame('image/png', $image->mimeType);
        self::assertSame(1278, $image->width);
        self::assertSame(1278, $image->height);
    }

    public function test_ticket_without_card_keeps_the_regular_picture(): void
    {
        $image = $this->imageService()->image($this->ticket(null));

        self::assertFalse($image->isCard);
        self::assertSame(1278, $image->width);
    }

    public function test_zebra_label_does_not_change_when_the_event_has_a_card(): void
    {
        app()->setLocale('fr');
        $zebra = new AttendeeTicketZplService(new TicketLayoutBuilder(new ZplImageConverter), new ZplTicketRenderer);

        $withCard = $zebra->generate($this->ticket($this->design()));

        self::assertSame($zebra->generate($this->ticket(null)), $withCard);
        self::assertStringContainsString('^PW639', $withCard);
        self::assertStringContainsString('^FDQA,A-K7QZ2M4^FS', $withCard);
    }

    public function test_card_lines_come_from_the_settings(): void
    {
        $design = $this->design();

        self::assertSame('Invitation valable pour 2 personnes', $design->guestsLine);
        self::assertSame('Dress code : bleu nuit, champagne et doré', $design->dressCodeLine);
        self::assertSame("QR code et pièce d'identité à l'entrée", $design->footerLine);
        self::assertSame('#06192b', $design->qrColor);
        self::assertNull($design->frame);
    }

    public function test_card_settings_can_hide_lines_and_set_the_frame(): void
    {
        $design = TicketCardDesignDTO::fromSettings(json_encode([
            'background_path' => 'ticket-cards/event-3/fond.png',
            'frame' => ['x' => 10, 'y' => 20, 'width' => 300, 'height' => 100],
            'footer' => '',
            'colors' => ['qr' => '#112233', 'text' => 'red'],
        ]), 's3-private');

        self::assertSame('s3-private', $design->disk);
        self::assertSame(['x' => 10, 'y' => 20, 'width' => 300, 'height' => 100], $design->frame);
        self::assertSame('', $design->guestsLine);
        self::assertSame('', $design->dressCodeLine);
        self::assertSame('', $design->footerLine);
        self::assertSame('#112233', $design->qrColor);
        self::assertSame(TicketCardDesignDTO::DEFAULT_TEXT_COLOR, $design->textColor);
    }

    public function test_no_card_without_background(): void
    {
        self::assertNull(TicketCardDesignDTO::fromSettings(null, 'local'));
        self::assertNull(TicketCardDesignDTO::fromSettings(['guests' => 2], 'local'));
    }
}
