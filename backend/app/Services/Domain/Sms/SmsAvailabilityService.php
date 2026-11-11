<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use Throwable;

/**
 * Whether an organizer can send SMS: its own line or the line it shares, with an API key and a sending number.
 */
class SmsAvailabilityService
{
    /** @var array<int, bool> */
    private array $byOrganizerId = [];

    public function __construct(
        private readonly OrganizerSmsSettingRepositoryInterface $settingsRepository,
        private readonly SmsLineService $lineService,
    ) {}

    public function canSend(int $organizerId): bool
    {
        if (array_key_exists($organizerId, $this->byOrganizerId)) {
            return $this->byOrganizerId[$organizerId];
        }

        try {
            /** @var OrganizerSmsSettingDomainObject|null $settings */
            $settings = $this->settingsRepository->findFirstWhere([
                OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            ]);
            $line = $settings !== null ? $this->lineService->resolveLine($settings) : null;
            $available = $line !== null && $line->getApiKey() && $line->getFromNumber();
        } catch (Throwable) {
            $available = false;
        }

        return $this->byOrganizerId[$organizerId] = (bool) $available;
    }
}
