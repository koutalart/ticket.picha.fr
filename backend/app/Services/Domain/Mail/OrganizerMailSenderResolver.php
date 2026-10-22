<?php

namespace HiEvents\Services\Domain\Mail;

use HiEvents\DomainObjects\OrganizerSettingDomainObject;
use HiEvents\Repository\Interfaces\OrganizerSettingsRepositoryInterface;
use Illuminate\Mail\Mailables\Address;
use Psr\Log\LoggerInterface;
use Throwable;

class OrganizerMailSenderResolver
{
    public const MAX_SENDER_NAME_LENGTH = 70;

    public const FORBIDDEN_CHARACTERS_PATTERN = '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}<>]/u';

    public function __construct(
        private readonly OrganizerSettingsRepositoryInterface $organizerSettingsRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function resolveForOrganizer(?int $organizerId): ?Address
    {
        if ($organizerId === null) {
            return null;
        }

        try {
            /** @var OrganizerSettingDomainObject|null $settings */
            $settings = $this->organizerSettingsRepository->findFirstWhere([
                'organizer_id' => $organizerId,
            ]);
        } catch (Throwable $exception) {
            $this->logger->warning('Unable to load the organizer e-mail sender name, using the platform default', [
                'organizer_id' => $organizerId,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        return $this->buildAddress($settings?->getEmailSenderName());
    }

    public function buildAddress(?string $senderName): ?Address
    {
        $senderName = $this->sanitize($senderName);
        $address = config('mail.from.address');

        if ($senderName === null || empty($address)) {
            return null;
        }

        return new Address($address, $senderName);
    }

    public function sanitize(?string $senderName): ?string
    {
        if ($senderName === null) {
            return null;
        }

        $senderName = strip_tags($senderName);
        $senderName = preg_replace(self::FORBIDDEN_CHARACTERS_PATTERN, ' ', $senderName) ?? '';
        $senderName = preg_replace('/\s+/u', ' ', $senderName) ?? '';
        $senderName = trim(mb_substr(trim($senderName), 0, self::MAX_SENDER_NAME_LENGTH));

        return $senderName === '' ? null : $senderName;
    }
}
