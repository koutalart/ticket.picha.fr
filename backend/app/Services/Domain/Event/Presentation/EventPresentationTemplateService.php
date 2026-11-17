<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Event\Presentation;

use HiEvents\DomainObjects\Enums\EventCategory;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;

/**
 * The default PICHA event page: the layout of Innocent Event's Halloween page (event 7), short header,
 * blurred cover behind the card, "Acheter" button, branded ticket. Content and brand colours stay the event's own.
 */
class EventPresentationTemplateService
{
    public const DEFAULT_ACCENT = '#faa200ff';

    private const BACKGROUND = '#171717';

    private const FONT = 'Outfit';

    private const BUY_BUTTON_TEXT = 'Acheter';

    public function __construct(
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly ImageRepositoryInterface $imageRepository,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function apply(int $eventId, int $organizerId, ?string $accent = null): void
    {
        $accent ??= self::DEFAULT_ACCENT;

        /** @var EventSettingDomainObject|null $settings */
        $settings = $this->eventSettingsRepository->findFirstWhere(['event_id' => $eventId]);

        if ($settings === null) {
            throw new ResourceNotFoundException(__('The event has no settings.'));
        }

        /** @var ImageDomainObject|null $logo */
        $logo = $this->imageRepository->findFirstWhere([
            'entity_id' => $organizerId,
            'entity_type' => OrganizerDomainObject::class,
            'type' => ImageType::ORGANIZER_LOGO->name,
        ]);

        $this->eventSettingsRepository->updateFromArray($settings->getId(), [
            'continue_button_text' => self::BUY_BUTTON_TEXT,
            'hide_getting_started_page' => true,
            'embedded_registration_enabled' => true,
            'homepage_primary_color' => $accent,
            'homepage_body_background_color' => self::BACKGROUND,
            'homepage_background_type' => 'MIRROR_COVER_IMAGE',
            'homepage_theme_settings' => [
                'mode' => 'light',
                'accent' => $accent,
                'background' => self::BACKGROUND,
                'font_family' => self::FONT,
                'hero_layout' => 'CARD',
                'background_type' => 'MIRROR_COVER_IMAGE',
            ],
            'ticket_design_settings' => [
                'enabled' => true,
                'accent_color' => $accent,
                'logo_image_id' => $logo?->getId(),
                'date_display_mode' => 'START_DATE_TIME',
            ],
            'homepage_footer_settings' => [
                'terms_url' => null,
                'event_links' => [],
                'related_links' => [],
                'newsletter_title' => null,
                'accessibility_url' => null,
                'newsletter_enabled' => true,
                'privacy_policy_url' => null,
            ],
        ]);

        $this->eventRepository->updateWhere(
            attributes: ['category' => EventCategory::MUSIC->value],
            where: ['id' => $eventId],
        );
    }
}
