<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\Helper\Url;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

class SmsSettingsService
{
    public const WEBHOOK_PATH = 'public/webhooks/httpsms/';

    public function __construct(
        private readonly OrganizerSmsSettingRepositoryInterface $settingsRepository,
    ) {}

    public function getOrCreate(int $accountId, int $organizerId): OrganizerSmsSettingDomainObject
    {
        $where = [OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ID => $organizerId];

        /** @var OrganizerSmsSettingDomainObject|null $settings */
        $settings = $this->settingsRepository->findFirstWhere($where);
        if ($settings !== null) {
            return $settings;
        }

        try {
            return $this->settingsRepository->create([
                ...$where,
                OrganizerSmsSettingDomainObjectAbstract::ACCOUNT_ID => $accountId,
                OrganizerSmsSettingDomainObjectAbstract::WEBHOOK_TOKEN => Str::random(48),
                OrganizerSmsSettingDomainObjectAbstract::WEBHOOK_SIGNING_KEY => $this->generateSigningKey(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->settingsRepository->findFirstWhere($where);
        }
    }

    public function regenerateSigningKey(OrganizerSmsSettingDomainObject $settings): OrganizerSmsSettingDomainObject
    {
        return $this->settingsRepository->updateFromArray($settings->getId(), [
            OrganizerSmsSettingDomainObjectAbstract::WEBHOOK_SIGNING_KEY => $this->generateSigningKey(),
        ]);
    }

    public function findByWebhookToken(string $token): ?OrganizerSmsSettingDomainObject
    {
        return $this->settingsRepository->findFirstWhere([
            OrganizerSmsSettingDomainObjectAbstract::WEBHOOK_TOKEN => $token,
        ]);
    }

    /**
     * The settings holding the httpSMS line this organizer sends with, when it uses a line shared by another organizer.
     */
    public function findSharedLine(OrganizerSmsSettingDomainObject $settings): ?OrganizerSmsSettingDomainObject
    {
        if ($settings->getSharedLineSettingsId() === null) {
            return null;
        }

        /** @var OrganizerSmsSettingDomainObject|null $line */
        $line = $this->settingsRepository->findFirstWhere([
            OrganizerSmsSettingDomainObjectAbstract::ID => $settings->getSharedLineSettingsId(),
        ]);

        if ($line === null || $line->getSharedLineSettingsId() !== null || $line->getId() === $settings->getId()) {
            return null;
        }

        return $line;
    }

    public function getWebhookUrl(OrganizerSmsSettingDomainObject $settings): string
    {
        return Url::getApiUrl(self::WEBHOOK_PATH.$settings->getWebhookToken());
    }

    private function generateSigningKey(): string
    {
        return Str::random(40);
    }
}
