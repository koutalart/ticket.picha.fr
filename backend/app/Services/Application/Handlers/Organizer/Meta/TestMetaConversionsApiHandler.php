<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Organizer\Meta;

use Carbon\Carbon;
use HiEvents\DomainObjects\Generated\OrganizerSettingDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\Exceptions\MetaConversionsApiException;
use HiEvents\Exceptions\MetaConversionsApiNotConfiguredException;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Organizer\Meta\DTO\TestMetaConversionsApiResultDTO;
use HiEvents\Services\Infrastructure\Meta\MetaConversionsApiClient;

class TestMetaConversionsApiHandler
{
    public function __construct(
        private readonly OrganizerSettingsRepositoryInterface $organizerSettingsRepository,
        private readonly MetaConversionsApiClient $client,
    ) {}

    /**
     * @throws MetaConversionsApiException
     * @throws MetaConversionsApiNotConfiguredException
     */
    public function handle(int $organizerId, ?string $ipAddress, ?string $userAgent): TestMetaConversionsApiResultDTO
    {
        /** @var OrganizerSettingDomainObject|null $settings */
        $settings = $this->organizerSettingsRepository->findFirstWhere([
            OrganizerSettingDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($settings === null || ! $settings->isMetaConversionsApiConfigured()) {
            throw new MetaConversionsApiNotConfiguredException(
                __('Add an enabled Meta Pixel and a Conversions API access token first.')
            );
        }

        $pixelId = $settings->getEnabledFacebookPixelId();
        $pixelName = $this->client->getPixelName($pixelId, $settings->getMetaConversionsApiToken());

        $testEventCode = $settings->getMetaTestEventCode();

        if ($testEventCode) {
            $this->client->sendEvents(
                pixelId: $pixelId,
                accessToken: $settings->getMetaConversionsApiToken(),
                events: [[
                    'event_name' => 'PageView',
                    'event_time' => Carbon::now()->getTimestamp(),
                    'action_source' => 'website',
                    'event_source_url' => config('app.frontend_url'),
                    'user_data' => array_filter([
                        'client_ip_address' => $ipAddress,
                        'client_user_agent' => $userAgent,
                    ]),
                ]],
                testEventCode: $testEventCode,
            );
        }

        return new TestMetaConversionsApiResultDTO(
            pixel_id: $pixelId,
            pixel_name: $pixelName,
            test_event_sent: (bool) $testEventCode,
        );
    }
}
