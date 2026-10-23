<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Enums\SmsOptOutSource;
use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsMessageDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsOptOutDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsUnattributedMessageDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\SmsUnattributedMessageDomainObject;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SmsSharedLineException;
use HiEvents\Helper\SmsKeywordDetector;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsOptOutRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsUnattributedMessageRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\InboundSmsDTO;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * SMS received on a shared line that no organizer of the line wrote to recently.
 * They are kept for the platform only, never shown to an organizer until an admin assigns them.
 */
class SmsUnattributedSmsService
{
    public function __construct(
        private readonly SmsUnattributedMessageRepositoryInterface $unattributedRepository,
        private readonly SmsMessageRepositoryInterface $messageRepository,
        private readonly SmsOptOutRepositoryInterface $optOutRepository,
        private readonly OrganizerSmsSettingRepositoryInterface $settingsRepository,
        private readonly SmsConversationService $conversationService,
        private readonly SmsInboundService $inboundService,
        private readonly SmsLineService $lineService,
        private readonly DatabaseManager $databaseManager,
    ) {}

    public function isKnown(string $providerMessageId): bool
    {
        return $this->unattributedRepository->findFirstWhere([
            SmsUnattributedMessageDomainObjectAbstract::PROVIDER_MESSAGE_ID => $providerMessageId,
        ]) !== null;
    }

    /**
     * Stores the SMS for the platform. A STOP is applied to every organizer of the line, as nobody knows who it was meant for.
     *
     * @param  Collection<int, OrganizerSmsSettingDomainObject>  $members
     *
     * @throws Throwable
     */
    public function record(
        OrganizerSmsSettingDomainObject $line,
        Collection $members,
        InboundSmsDTO $sms,
        string $phone,
    ): ?SmsUnattributedMessageDomainObject {
        if ($this->isKnown($sms->providerMessageId) || $this->messageRepository->findFirstWhere([
            SmsMessageDomainObjectAbstract::PROVIDER_MESSAGE_ID => $sms->providerMessageId,
        ]) !== null) {
            return null;
        }

        $isStop = SmsKeywordDetector::detect($sms->content) === SmsKeywordDetector::STOP;

        try {
            return $this->databaseManager->transaction(function () use ($line, $members, $sms, $phone, $isStop) {
                /** @var SmsUnattributedMessageDomainObject $message */
                $message = $this->unattributedRepository->create([
                    SmsUnattributedMessageDomainObjectAbstract::LINE_SETTINGS_ID => $line->getId(),
                    SmsUnattributedMessageDomainObjectAbstract::PHONE => $phone,
                    SmsUnattributedMessageDomainObjectAbstract::CONTENT => $sms->content,
                    SmsUnattributedMessageDomainObjectAbstract::PROVIDER_MESSAGE_ID => $sms->providerMessageId,
                    SmsUnattributedMessageDomainObjectAbstract::PROVIDER_TIMESTAMP => $this->parseTimestamp($sms->timestamp),
                    SmsUnattributedMessageDomainObjectAbstract::OPT_OUT_APPLIED => $isStop,
                ]);

                if ($isStop) {
                    foreach ($members as $member) {
                        $this->optOut($member->getOrganizerId(), $phone);
                    }
                }

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * @return Collection<int, SmsUnattributedMessageDomainObject>
     */
    public function listPending(int $limit = 50): Collection
    {
        return $this->unattributedRepository->findWhere(
            where: [[SmsUnattributedMessageDomainObjectAbstract::ASSIGNED_AT, 'null', null]],
            orderAndDirections: [new OrderAndDirection(SmsUnattributedMessageDomainObjectAbstract::ID, 'desc')],
        )->take($limit)->values();
    }

    /**
     * Moves the SMS into the inbox of an organizer of its line.
     *
     * @throws ResourceNotFoundException
     * @throws SmsSharedLineException
     * @throws InvalidSmsPhoneNumberException
     * @throws Throwable
     */
    public function assign(int $unattributedId, int $organizerId): SmsMessageDomainObject
    {
        /** @var SmsUnattributedMessageDomainObject|null $unattributed */
        $unattributed = $this->unattributedRepository->findFirstWhere([
            SmsUnattributedMessageDomainObjectAbstract::ID => $unattributedId,
        ]);

        if ($unattributed === null) {
            throw new ResourceNotFoundException(__('Unattributed SMS not found'));
        }

        if ($unattributed->getAssignedAt() !== null) {
            throw new SmsSharedLineException(__('This SMS has already been assigned'));
        }

        /** @var OrganizerSmsSettingDomainObject|null $line */
        $line = $this->settingsRepository->findFirstWhere([
            OrganizerSmsSettingDomainObjectAbstract::ID => $unattributed->getLineSettingsId(),
        ]);

        $target = $line === null ? null : $this->lineService->candidates($line)
            ->first(fn (OrganizerSmsSettingDomainObject $member) => $member->getOrganizerId() === $organizerId);

        if ($target === null) {
            throw new SmsSharedLineException(__('This organizer does not use the line that received the SMS'));
        }

        return $this->databaseManager->transaction(function () use ($unattributed, $target, $organizerId) {
            $message = $this->inboundService->recordInbound($target, new InboundSmsDTO(
                providerMessageId: $unattributed->getProviderMessageId(),
                contact: $unattributed->getPhone(),
                content: $unattributed->getContent(),
                timestamp: $unattributed->getProviderTimestamp(),
            ), lineSettingsId: $unattributed->getLineSettingsId());

            if ($message === null) {
                throw new SmsSharedLineException(__('This SMS is already in an organizer inbox'));
            }

            $this->unattributedRepository->updateFromArray($unattributed->getId(), [
                SmsUnattributedMessageDomainObjectAbstract::ASSIGNED_ORGANIZER_ID => $organizerId,
                SmsUnattributedMessageDomainObjectAbstract::SMS_MESSAGE_ID => $message->getId(),
                SmsUnattributedMessageDomainObjectAbstract::ASSIGNED_AT => Carbon::now('UTC')->toDateTimeString(),
            ]);

            return $message;
        });
    }

    private function optOut(int $organizerId, string $phone): void
    {
        if ($this->conversationService->isOptedOut($organizerId, $phone)) {
            return;
        }

        $this->optOutRepository->create([
            SmsOptOutDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            SmsOptOutDomainObjectAbstract::PHONE => $phone,
            SmsOptOutDomainObjectAbstract::SOURCE => SmsOptOutSource::KEYWORD->name,
        ]);
    }

    private function parseTimestamp(?string $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        try {
            return Carbon::parse($timestamp)->utc()->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }
}
