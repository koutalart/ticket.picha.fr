<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Generated\OrganizerDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsSharedLineException;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A line is the settings row holding the httpSMS connection (API key, number, webhook).
 * Other organizers can send with it by pointing their own settings to it with shared_line_settings_id.
 */
class SmsLineService
{
    public const DEFAULT_ATTRIBUTION_DAYS = 30;

    public function __construct(
        private readonly OrganizerSmsSettingRepositoryInterface $settingsRepository,
        private readonly SmsMessageRepositoryInterface $messageRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly SmsSettingsService $settingsService,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * The settings to send with: the shared line for a member, the organizer's own settings otherwise.
     *
     * @throws SmsGatewayNotConfiguredException
     */
    public function resolveLine(OrganizerSmsSettingDomainObject $settings): OrganizerSmsSettingDomainObject
    {
        if ($settings->getSharedLineSettingsId() === null) {
            return $settings;
        }

        $line = $this->settingsService->findSharedLine($settings);
        if ($line === null) {
            throw new SmsGatewayNotConfiguredException(__('The shared SMS line of this organizer is not available'));
        }

        return $line;
    }

    /**
     * The line itself first, then the organizers attached to it.
     *
     * @return Collection<int, OrganizerSmsSettingDomainObject>
     */
    public function members(OrganizerSmsSettingDomainObject $line): Collection
    {
        return collect([$line])
            ->merge($this->settingsRepository->findWhere([
                OrganizerSmsSettingDomainObjectAbstract::SHARED_LINE_SETTINGS_ID => $line->getId(),
            ]))
            ->values();
    }

    public function isShared(OrganizerSmsSettingDomainObject $settings): bool
    {
        return $settings->getSharedLineSettingsId() !== null
            || $this->settingsRepository->countWhere([
                OrganizerSmsSettingDomainObjectAbstract::SHARED_LINE_SETTINGS_ID => $settings->getId(),
            ]) > 0;
    }

    /**
     * The organizer an incoming SMS belongs to: the only one on the line, otherwise the one who wrote last
     * to this number within the attribution window. Null when nobody did.
     *
     * @param  Collection<int, OrganizerSmsSettingDomainObject>  $members
     */
    public function attributeInbound(Collection $members, string $phone): ?OrganizerSmsSettingDomainObject
    {
        if ($members->count() <= 1) {
            return $members->first();
        }

        $organizerId = $this->messageRepository->findLastOutboundOrganizerId(
            $members->map(fn (OrganizerSmsSettingDomainObject $member) => $member->getOrganizerId())->values()->all(),
            $phone,
            Carbon::now('UTC')->subDays($this->attributionWindowDays())->toDateTimeString(),
        );

        if ($organizerId === null) {
            return null;
        }

        return $members->first(fn (OrganizerSmsSettingDomainObject $member) => $member->getOrganizerId() === $organizerId);
    }

    public function attributionWindowDays(): int
    {
        return max(1, (int) $this->config->get('services.httpsms.shared_line_attribution_days', self::DEFAULT_ATTRIBUTION_DAYS));
    }

    /**
     * @throws SmsSharedLineException
     */
    public function attach(int $organizerId, int $lineOrganizerId): OrganizerSmsSettingDomainObject
    {
        if ($organizerId === $lineOrganizerId) {
            throw new SmsSharedLineException(__('An organizer cannot share its own line with itself'));
        }

        /** @var OrganizerSmsSettingDomainObject|null $line */
        $line = $this->settingsRepository->findFirstWhere([
            OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ID => $lineOrganizerId,
        ]);

        if ($line === null || ! $line->getApiKey() || ! $line->getFromNumber()) {
            throw new SmsSharedLineException(__('The line to share has no PICHA SMS API key or sending number'));
        }

        if ($line->getSharedLineSettingsId() !== null) {
            throw new SmsSharedLineException(__('This organizer already uses a shared line and cannot share it'));
        }

        $settings = $this->settingsService->getOrCreate($this->findOrganizer($organizerId)->getAccountId(), $organizerId);

        if ($this->settingsRepository->countWhere([
            OrganizerSmsSettingDomainObjectAbstract::SHARED_LINE_SETTINGS_ID => $settings->getId(),
        ]) > 0) {
            throw new SmsSharedLineException(__('Other organizers use the line of this organizer'));
        }

        return $this->settingsRepository->updateFromArray($settings->getId(), [
            OrganizerSmsSettingDomainObjectAbstract::SHARED_LINE_SETTINGS_ID => $line->getId(),
        ]);
    }

    /**
     * @throws SmsSharedLineException
     */
    public function detach(int $organizerId): OrganizerSmsSettingDomainObject
    {
        /** @var OrganizerSmsSettingDomainObject|null $settings */
        $settings = $this->settingsRepository->findFirstWhere([
            OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($settings === null || $settings->getSharedLineSettingsId() === null) {
            throw new SmsSharedLineException(__('This organizer does not use a shared line'));
        }

        return $this->settingsRepository->updateFromArray($settings->getId(), [
            OrganizerSmsSettingDomainObjectAbstract::SHARED_LINE_SETTINGS_ID => null,
        ]);
    }

    /**
     * @throws SmsSharedLineException
     */
    private function findOrganizer(int $organizerId): OrganizerDomainObject
    {
        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $this->organizerRepository->findFirstWhere([OrganizerDomainObjectAbstract::ID => $organizerId]);
        if ($organizer === null) {
            throw new SmsSharedLineException(__('Organizer not found'));
        }

        return $organizer;
    }
}
