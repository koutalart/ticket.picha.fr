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

    public function __construct(
        private readonly UpdateOrganizerSiteContentHandler $handler,
    )
    {
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
