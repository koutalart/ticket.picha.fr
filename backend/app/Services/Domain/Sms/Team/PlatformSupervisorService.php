<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\Team;

use HiEvents\Helper\SmsPhoneNormalizer;
use Illuminate\Config\Repository as Config;

/**
 * The platform supervisors (PLATFORM_SUPERVISOR_PHONES) watch every organizer. They belong to no organizer team:
 * their SMS never reach an assistant and never move the attribution of a shared line.
 */
class PlatformSupervisorService
{
    public function __construct(
        private readonly Config $config,
    ) {}

    /**
     * @return array<int, string>
     */
    public function phones(): array
    {
        $phones = [];
        foreach (explode(',', (string) $this->config->get('services.platform_supervisor.phones')) as $raw) {
            $phone = trim($raw) !== '' ? SmsPhoneNormalizer::normalize($raw) : null;
            if ($phone !== null) {
                $phones[] = $phone;
            }
        }

        return array_values(array_unique($phones));
    }

    public function isSupervisor(?string $phone): bool
    {
        if ($phone === null || trim($phone) === '') {
            return false;
        }

        $normalized = SmsPhoneNormalizer::normalize($phone);

        return $normalized !== null && in_array($normalized, $this->phones(), true);
    }
}
