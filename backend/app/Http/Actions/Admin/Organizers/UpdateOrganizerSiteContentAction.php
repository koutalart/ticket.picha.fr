<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Admin\Organizers;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Exceptions\OrganizerNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Resources\Organizer\AdminOrganizerResource;
use HiEvents\Services\Application\Handlers\Admin\DTO\UpdateOrganizerSiteContentDTO;
use HiEvents\Services\Application\Handlers\Admin\UpdateOrganizerSiteContentHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UpdateOrganizerSiteContentAction extends BaseAction
{
    private const URL_RULE = 'nullable|url:http,https|max:1000';

    private const SEO_TITLE_KEYS = [
        'seo_title_home',
        'seo_title_services',
        'seo_title_about',
        'seo_title_partners',
        'seo_title_events',
        'seo_title_contact',
    ];

    private const LABEL_KEYS = [
        'about_button_label',
        'partners_subtitle',
        'services_cta_title',
        'services_cta_text',
        'partner_card_1_text',
        'partner_card_2_text',
        'partner_card_3_text',
        'partners_button_label',
        'contact_intro',
        'contact_write_text',
        'events_intro',
    ];

    public function __construct(
        private readonly UpdateOrganizerSiteContentHandler $handler,
    )
    {
    }

    private function labelRules(): array
    {
        $rules = [];
        foreach (self::SEO_TITLE_KEYS as $key) {
            $rules[$key] = 'nullable|string|max:200';
        }
        foreach (self::LABEL_KEYS as $key) {
            $rules[$key] = 'nullable|string|max:600';
        }

        return $rules;
    }

    public function __invoke(Request $request, int $accountId, int $organizerId): JsonResponse
    {
        $this->minimumAllowedRole(Role::SUPERADMIN);

        $validated = $request->validate([
            'tagline' => 'nullable|string|max:200',
            'area' => 'nullable|string|max:80',
            'about_headline' => 'nullable|string|max:200',
            'story' => 'nullable|string|max:20000',
            'vision' => 'nullable|string|max:20000',
            'about_image_url' => self::URL_RULE,
            'values' => 'nullable|array|max:8',
            'values.*.title' => 'required|string|max:80',
            'values.*.text' => 'nullable|string|max:400',
            'stats' => 'nullable|array|max:6',
            'stats.*.value' => 'required|string|max:20',
            'stats.*.label' => 'required|string|max:60',
            'team' => 'nullable|array|max:24',
            'team.*.name' => 'required|string|max:80',
            'team.*.role' => 'nullable|string|max:80',
            'team.*.photo_url' => self::URL_RULE,
            'gallery' => 'nullable|array|max:60',
            'gallery.*.url' => 'required|url:http,https|max:1000',
            'gallery.*.caption' => 'nullable|string|max:200',
            'services_intro' => 'nullable|string|max:1000',
            'services' => 'nullable|array|max:12',
            'services.*.title' => 'required|string|max:80',
            'services.*.text' => 'nullable|string|max:600',
            'partners_intro' => 'nullable|string|max:1000',
            'partners' => 'nullable|array|max:60',
            'partners.*.name' => 'required|string|max:100',
            'partners.*.logo_url' => self::URL_RULE,
            'partners.*.url' => self::URL_RULE,
            'partners.*.category' => 'nullable|string|max:60',
            'press_text' => 'nullable|string|max:2000',
            'press_email' => 'nullable|email|max:200',
            'press_phone' => 'nullable|string|max:40',
            'press_kit_url' => self::URL_RULE,
            'intro_title' => 'nullable|string|max:120',
            'intro_text' => 'nullable|string|max:600',
            'upcoming_note' => 'nullable|string|max:200',
            'notify_url' => self::URL_RULE,
            'videos' => 'nullable|array|max:20',
            'videos.*.youtube_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{11}$/'],
            'videos.*.title' => 'required|string|max:100',
            'videos.*.subtitle' => 'nullable|string|max:100',
            'practical' => 'nullable|array|max:6',
            'practical.*.title' => 'required|string|max:80',
            'practical.*.text' => 'nullable|string|max:400',
            'redirect_to_current_event' => 'nullable|boolean',
            ...$this->labelRules(),
        ]);

        try {
            $organizer = $this->handler->handle(new UpdateOrganizerSiteContentDTO(
                accountId: $accountId,
                organizerId: $organizerId,
                siteContent: $validated,
            ));
        } catch (OrganizerNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->resourceResponse(
            resource: AdminOrganizerResource::class,
            data: $organizer,
        );
    }
}
