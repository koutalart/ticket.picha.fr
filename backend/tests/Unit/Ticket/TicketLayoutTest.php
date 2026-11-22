<?php

declare(strict_types=1);

namespace Tests\Unit\Ticket;

use HiEvents\Services\Domain\Ticket\DTO\TicketDataDTO;
use HiEvents\Services\Domain\Ticket\Layout\TicketQrElement;
use HiEvents\Services\Domain\Ticket\Layout\TicketTextElement;
use HiEvents\Services\Domain\Ticket\PngTicketRenderer;
use HiEvents\Services\Domain\Ticket\TicketLayoutBuilder;
use HiEvents\Services\Domain\Ticket\ZplImageConverter;
use HiEvents\Services\Domain\Ticket\ZplTicketRenderer;
use Imagick;
use Tests\TestCase;

class TicketLayoutTest extends TestCase
{
    private function ticket(string $status = TicketDataDTO::STATUS_VALID, ?string $sponsorLogo = null, string $sponsorName = ''): TicketDataDTO
    {
        return new TicketDataDTO(
            publicId: 'A-SFMVW8P',
            eventTitle: 'Triangle des Bermudes',
            productTitle: 'Entrée simple',
            attendeeName: 'Jane Doe',
            eventDate: 'Dim. 11 oct. 2026',
            eventTime: '18h00',
            venue: 'Le 5/5, Mamoudzou',
            sellerName: 'Innocent Event',
            sponsorLogo: $sponsorLogo,
            sponsorName: $sponsorName,
            status: $status,
        );
    }

    private function builder(): TicketLayoutBuilder
    {
        return new TicketLayoutBuilder(new ZplImageConverter);
    }

    public function test_png_and_zpl_come_from_the_same_layout(): void
    {
        app()->setLocale('fr');
        $layout = $this->builder()->build($this->ticket());

        $zpl = (new ZplTicketRenderer)->render($layout);
        self::assertStringContainsString('^FO366,268^BQN,2,9^FDQA,A-SFMVW8P^FS', $zpl);

        $png = (new PngTicketRenderer)->render($layout);
        $image = new Imagick;
        $image->readImageBlob($png);
        self::assertSame('PNG', $image->getImageFormat());
        self::assertSame([1278, 1278], [$image->getImageWidth(), $image->getImageHeight()]);
    }

    public function test_cancelled_ticket_has_no_qr_code(): void
    {
        app()->setLocale('fr');
        $layout = $this->builder()->build($this->ticket(TicketDataDTO::STATUS_CANCELLED));

        $qrCodes = array_filter($layout->elements, fn ($element) => $element instanceof TicketQrElement);
        $texts = array_map(fn (TicketTextElement $text) => $text->text, array_values(array_filter(
            $layout->elements,
            fn ($element) => $element instanceof TicketTextElement,
        )));

        self::assertCount(0, $qrCodes);
        self::assertContains('ANNULÉ', $texts);
        self::assertNotContains('S F M V W 8 P', $texts);
    }

    public function test_awaiting_payment_ticket_has_no_qr_code(): void
    {
        app()->setLocale('fr');
        $zpl = (new ZplTicketRenderer)->render($this->builder()->build($this->ticket(TicketDataDTO::STATUS_AWAITING_PAYMENT)));

        self::assertStringNotContainsString('^BQN', $zpl);
        self::assertStringContainsString('PAIEMENT EN ATTENTE', $zpl);
    }

    /**
     * @return list<string>
     */
    private function texts(TicketDataDTO $ticket): array
    {
        app()->setLocale('fr');

        return array_map(fn (TicketTextElement $text) => $text->text, array_values(array_filter(
            $this->builder()->build($ticket)->elements,
            fn ($element) => $element instanceof TicketTextElement,
        )));
    }

    private function sponsorLogo(): string
    {
        $image = new Imagick;
        $image->newImage(200, 80, 'black');
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }

    public function test_sponsor_name_is_printed_in_place_of_a_missing_logo(): void
    {
        $texts = $this->texts($this->ticket(sponsorName: 'Boissons du Lagon'));

        self::assertContains('Sponsor', $texts);
        self::assertContains('Boissons du Lagon', $texts);
    }

    public function test_sponsor_name_is_printed_under_the_sponsor_logo(): void
    {
        $layout = $this->builder()->build($this->ticket(sponsorLogo: $this->sponsorLogo(), sponsorName: 'Boissons du Lagon'));
        $name = array_values(array_filter(
            $layout->elements,
            fn ($element) => $element instanceof TicketTextElement && $element->text === 'Boissons du Lagon',
        ));

        self::assertCount(1, $name);
        self::assertSame(132, $name[0]->y);
    }

    public function test_no_sponsor_block_without_logo_or_name(): void
    {
        self::assertNotContains('Sponsor', $this->texts($this->ticket()));
    }

    public function test_free_ticket_has_no_seller_line(): void
    {
        app()->setLocale('fr');
        $free = new TicketDataDTO(publicId: 'A-1', eventTitle: 'Foire', productTitle: 'Inscription', sellerName: 'CCI Mayotte', showSeller: false);
        $paid = new TicketDataDTO(publicId: 'A-1', eventTitle: 'Foire', productTitle: 'Entrée', sellerName: 'CCI Mayotte');

        $texts = fn (TicketDataDTO $t) => implode('|', array_map(
            fn ($e) => $e->text,
            array_filter($this->builder()->build($t)->elements, fn ($e) => $e instanceof TicketTextElement),
        ));

        self::assertStringNotContainsString(__('Seller: :name', ['name' => 'CCI Mayotte']), $texts($free));
        self::assertStringContainsString(__('Seller: :name', ['name' => 'CCI Mayotte']), $texts($paid));
    }

    public function test_long_title_wraps_on_two_lines_instead_of_being_cut(): void
    {
        app()->setLocale('fr');
        $title = 'La Foire de l\'Industrie de Mayotte 2026, Mamoudzou';
        $layout = $this->builder()->build(new TicketDataDTO(publicId: 'A-1', eventTitle: $title, productTitle: 'Inscription'));

        $element = array_values(array_filter($layout->elements, fn ($e) => $e instanceof TicketTextElement && $e->text === $title));

        self::assertCount(1, $element);
        self::assertSame(2, $element[0]->maxLines);
        self::assertSame(565, $element[0]->blockWidth);
    }
}
