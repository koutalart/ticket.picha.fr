<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Generated\SmsUnattributedOutboundMessageDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsUnattributedOutboundMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Interfaces\SmsUnattributedOutboundMessageRepositoryInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * SMS sent through the central queue for no organizer (Anicka's own scripts: codes, Display, welcome…).
 * They are never filed with an organizer; they are kept for the platform so the team reads them in Chatwoot.
 */
class SmsUnattributedOutboundService
{
    private const FINAL_STATUSES = [
        SmsMessageStatus::DELIVERED->name,
        SmsMessageStatus::FAILED->name,
        SmsMessageStatus::EXPIRED->name,
    ];

    public function __construct(
        private readonly SmsUnattributedOutboundMessageRepositoryInterface $repository,
    ) {}

    public function record(
        OrganizerSmsSettingDomainObject $line,
        string $providerMessageId,
        string $contact,
        ?string $content,
        ?string $requestId,
        SmsMessageStatus $status,
        ?string $errorMessage,
    ): ?SmsUnattributedOutboundMessageDomainObject {
        /** @var SmsUnattributedOutboundMessageDomainObject|null $existing */
        $existing = $this->repository->findFirstWhere([
            SmsUnattributedOutboundMessageDomainObjectAbstract::PROVIDER_MESSAGE_ID => $providerMessageId,
        ]);

        if ($existing !== null) {
            return $this->updateStatus($existing, $status, $errorMessage);
        }

        $phone = SmsPhoneNormalizer::normalize($contact);
        if ($phone === null) {
            return null;
        }

        try {
            return $this->repository->create([
                SmsUnattributedOutboundMessageDomainObjectAbstract::LINE_SETTINGS_ID => $line->getId(),
                SmsUnattributedOutboundMessageDomainObjectAbstract::PHONE => $phone,
                SmsUnattributedOutboundMessageDomainObjectAbstract::CONTENT => (string) $content,
                SmsUnattributedOutboundMessageDomainObjectAbstract::PROVIDER_MESSAGE_ID => $providerMessageId,
                SmsUnattributedOutboundMessageDomainObjectAbstract::REQUEST_ID => $requestId,
                SmsUnattributedOutboundMessageDomainObjectAbstract::STATUS => $status->name,
                SmsUnattributedOutboundMessageDomainObjectAbstract::ERROR_MESSAGE => $errorMessage !== null ? mb_substr($errorMessage, 0, 500) : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function updateStatus(
        SmsUnattributedOutboundMessageDomainObject $message,
        SmsMessageStatus $status,
        ?string $errorMessage,
    ): SmsUnattributedOutboundMessageDomainObject {
        if ($message->getStatus() === $status->name
            || (in_array($message->getStatus(), self::FINAL_STATUSES, true) && ! in_array($status->name, self::FINAL_STATUSES, true))) {
            return $message;
        }

        return $this->repository->updateFromArray($message->getId(), [
            SmsUnattributedOutboundMessageDomainObjectAbstract::STATUS => $status->name,
            SmsUnattributedOutboundMessageDomainObjectAbstract::ERROR_MESSAGE => $errorMessage !== null ? mb_substr($errorMessage, 0, 500) : null,
        ]);
    }
}
