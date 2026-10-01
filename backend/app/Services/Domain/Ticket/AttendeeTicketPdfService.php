<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Ticket;

use Barryvdh\DomPDF\Facade\Pdf;
use GdImage;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use Illuminate\Support\Facades\File;

/**
 * D18 (PICHA_BOX_OFFICE_DECISIONS_REQUIRED.md): extraction of
 * AttendeeTicketMail::generateTicketPdf() so the Box Office ticket.pdf /
 * reprint endpoints can reuse the exact same rendering, without depending
 * on a Mailable.
 */
class AttendeeTicketPdfService
{
    private const ICONS = ['ticket', 'date', 'time', 'venue', 'globe'];

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly QrCodePngRenderer $qrCodePngRenderer,
        private readonly TicketContentService $ticketContentService,
    ) {}

    public function generate(
        AttendeeDomainObject $attendee,
        EventDomainObject $event,
        EventSettingDomainObject $eventSettings,
        OrganizerDomainObject $organizer,
    ): string {
        $product = $attendee->getProduct()
            ?? $this->productRepository->findById($attendee->getProductId());

        $designSettings = $eventSettings->getTicketDesignSettings();
        if (is_string($designSettings)) {
            $designSettings = json_decode($designSettings, true) ?? [];
        }
        $designSettings ??= [];
        $showDate = ($designSettings['date_display_mode'] ?? 'START_DATE_TIME') !== 'HIDDEN';

        $content = $this->ticketContentService->build($attendee, $event, $eventSettings, $organizer, $product);

        File::ensureDirectoryExists(storage_path('fonts'));

        return Pdf::setOption(['isFontSubsettingEnabled' => true])
            ->loadView('attendee-ticket-pdf', [
                'content' => $content,
                'rows' => $this->rows($content, $showDate),
                'displayId' => TicketText::displayId($content->public_id),
                'spaced' => TicketText::spaced(...),
                'qrCodeBase64' => base64_encode($this->qrCodePngRenderer->render($content->public_id, 300)),
                'eventLogo' => $this->pngDataUri($content->event_logo_image),
                'sponsorLogo' => $this->pngDataUri($content->sponsor_logo_image),
                'pichaLogo' => $this->fileDataUri(resource_path('ticket/picha-ai-logo.png'), 'image/png'),
                'icons' => $this->icons(),
                'fonts' => [
                    'regular' => resource_path('ticket/fonts/RobotoCondensed-Regular.ttf'),
                    'bold' => resource_path('ticket/fonts/RobotoCondensed-Bold.ttf'),
                ],
                'footerText' => $designSettings['footer_text'] ?? null,
            ])
            ->output();
    }

    /**
     * @return list<array{icon: string, label: string, value: string}>
     */
    private function rows(DTO\TicketContentDTO $content, bool $showDate): array
    {
        $venue = implode(', ', array_filter([$content->venue_name, $content->venue_city]));

        return array_values(array_filter([
            ['icon' => 'ticket', 'label' => __('Ticket type'), 'value' => $content->product_title],
            ['icon' => 'date', 'label' => __('Date'), 'value' => $showDate ? $content->event_when : ''],
            ['icon' => 'time', 'label' => __('Time'), 'value' => $showDate ? $content->event_hours : ''],
            ['icon' => 'venue', 'label' => __('Venue'), 'value' => $venue],
        ], fn (array $row): bool => $row['value'] !== ''));
    }

    /**
     * @return array<string, string>
     */
    private function icons(): array
    {
        $icons = [];
        foreach (self::ICONS as $icon) {
            $icons[$icon] = $this->fileDataUri(resource_path("ticket/icons/{$icon}.png"), 'image/png');
        }

        return $icons;
    }

    private function pngDataUri(?string $imageBytes): ?string
    {
        if ($imageBytes === null) {
            return null;
        }

        $image = @imagecreatefromstring($imageBytes);
        if (! $image instanceof GdImage) {
            return null;
        }

        imagesavealpha($image, true);
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    private function fileDataUri(string $path, string $mimeType): string
    {
        return 'data:'.$mimeType.';base64,'.base64_encode((string) file_get_contents($path));
    }
}
