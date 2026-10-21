<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Services\Application\Handlers\Sms\DTO\OrganizerSmsSettingsViewDTO;
use HiEvents\Services\Application\Handlers\Sms\DTO\UpdateOrganizerSmsSettingsDTO;
use HiEvents\Services\Domain\Sms\SmsSettingsService;

class UpdateOrganizerSmsSettingsHandler
{
    public function __construct(
        private readonly SmsSettingsService $settingsService,
        private readonly OrganizerSmsSettingRepositoryInterface $settingsRepository,
    ) {}

    /**
     * @throws InvalidSmsPhoneNumberException
     */
    public function handle(UpdateOrganizerSmsSettingsDTO $dto): OrganizerSmsSettingsViewDTO
    {
        $settings = $this->settingsService->getOrCreate($dto->accountId, $dto->organizerId);
        $attributes = [];

        if ($dto->wasProvided('apiKey')) {
            $apiKey = trim((string) $dto->apiKey);
            $attributes[OrganizerSmsSettingDomainObjectAbstract::API_KEY] = $apiKey !== '' ? $apiKey : null;
        }

        if ($dto->wasProvided('fromNumber')) {
            $attributes[OrganizerSmsSettingDomainObjectAbstract::FROM_NUMBER] = $this->normalizeFromNumber($dto->fromNumber);
        }

        if ($attributes !== []) {
            $settings = $this->settingsRepository->updateFromArray($settings->getId(), $attributes);
        }

        return new OrganizerSmsSettingsViewDTO(
            settings: $settings,
            webhookUrl: $this->settingsService->getWebhookUrl($settings),
        );
    }

    /**
     * @throws InvalidSmsPhoneNumberException
     */
    private function normalizeFromNumber(?string $fromNumber): ?string
    {
        if ($fromNumber === null || trim($fromNumber) === '') {
            return null;
        }

        $normalized = SmsPhoneNormalizer::normalize($fromNumber);
        if ($normalized === null) {
            throw new InvalidSmsPhoneNumberException(__('Invalid phone number'));
        }

        return $normalized;
    }
}
