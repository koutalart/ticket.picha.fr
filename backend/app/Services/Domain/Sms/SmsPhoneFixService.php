<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Generated\OrganizerTeamContactDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsConversationDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsOptOutDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerTeamContactDomainObject;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\SmsOptOutDomainObject;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Interfaces\OrganizerTeamContactRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsOptOutRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsPhoneFixItemDTO;
use Illuminate\Database\DatabaseManager;

/**
 * Conversations, STOP and team numbers saved with a Mayotte or La Réunion number written with +33 (+33693… instead of +262693…).
 * A conversation already existing under the right number for the same organizer receives the messages of the wrong
 * one instead of being duplicated. Only inserts and moves rows: no alert, no automatic reply.
 */
class SmsPhoneFixService
{
    public const ACTION_RENAMED = 'renamed';

    public const ACTION_MERGED = 'merged';

    public const ACTION_DUPLICATE_REMOVED = 'duplicate_removed';

    public function __construct(
        private readonly SmsConversationRepositoryInterface $conversationRepository,
        private readonly SmsOptOutRepositoryInterface $optOutRepository,
        private readonly OrganizerTeamContactRepositoryInterface $teamContactRepository,
        private readonly DatabaseManager $databaseManager,
    ) {}

    /**
     * @return array<int, SmsPhoneFixItemDTO>
     */
    public function fix(bool $dryRun): array
    {
        $items = [];

        /** @var SmsConversationDomainObject $conversation */
        foreach ($this->conversationRepository->findWithFrenchOverseasPhones() as $conversation) {
            $items[] = $this->fixConversation($conversation, $dryRun);
        }

        /** @var SmsOptOutDomainObject $optOut */
        foreach ($this->optOutRepository->findWithFrenchOverseasPhones() as $optOut) {
            $items[] = $this->fixOptOut($optOut, $dryRun);
        }

        /** @var OrganizerTeamContactDomainObject $contact */
        foreach ($this->teamContactRepository->findWithFrenchOverseasPhones() as $contact) {
            $items[] = $this->fixTeamContact($contact, $dryRun);
        }

        return $items;
    }

    private function fixConversation(SmsConversationDomainObject $conversation, bool $dryRun): SmsPhoneFixItemDTO
    {
        $phone = SmsPhoneNormalizer::overseasFromFrench($conversation->getPhone());

        /** @var SmsConversationDomainObject|null $target */
        $target = $this->conversationRepository->findFirstWhere([
            SmsConversationDomainObjectAbstract::ORGANIZER_ID => $conversation->getOrganizerId(),
            SmsConversationDomainObjectAbstract::PHONE => $phone,
        ]);

        $moved = 0;
        if (! $dryRun) {
            if ($target === null) {
                $this->conversationRepository->updateFromArray($conversation->getId(), [
                    SmsConversationDomainObjectAbstract::PHONE => $phone,
                ]);
            } else {
                $moved = $this->conversationRepository->mergeInto($conversation->getId(), $target->getId());
            }
        }

        return new SmsPhoneFixItemDTO(
            type: 'conversation',
            id: $conversation->getId(),
            organizerId: $conversation->getOrganizerId(),
            from: $conversation->getPhone(),
            to: $phone,
            action: $target === null ? self::ACTION_RENAMED : self::ACTION_MERGED,
            mergedIntoId: $target?->getId(),
            movedMessages: $moved,
        );
    }

    private function fixTeamContact(OrganizerTeamContactDomainObject $contact, bool $dryRun): SmsPhoneFixItemDTO
    {
        $phone = SmsPhoneNormalizer::overseasFromFrench($contact->getPhone());

        $existing = $this->teamContactRepository->findFirstWhere([
            OrganizerTeamContactDomainObjectAbstract::ORGANIZER_ID => $contact->getOrganizerId(),
            OrganizerTeamContactDomainObjectAbstract::PHONE => $phone,
        ]);

        if (! $dryRun) {
            if ($existing !== null) {
                $this->teamContactRepository->deleteWhere([OrganizerTeamContactDomainObjectAbstract::ID => $contact->getId()]);
            } else {
                $this->teamContactRepository->updateFromArray($contact->getId(), [OrganizerTeamContactDomainObjectAbstract::PHONE => $phone]);
            }
        }

        return new SmsPhoneFixItemDTO(
            type: 'team',
            id: $contact->getId(),
            organizerId: $contact->getOrganizerId(),
            from: $contact->getPhone(),
            to: $phone,
            action: $existing !== null ? self::ACTION_DUPLICATE_REMOVED : self::ACTION_RENAMED,
        );
    }

    private function fixOptOut(SmsOptOutDomainObject $optOut, bool $dryRun): SmsPhoneFixItemDTO
    {
        $phone = SmsPhoneNormalizer::overseasFromFrench($optOut->getPhone());

        $existing = $this->optOutRepository->findFirstWhere([
            SmsOptOutDomainObjectAbstract::ORGANIZER_ID => $optOut->getOrganizerId(),
            SmsOptOutDomainObjectAbstract::PHONE => $phone,
        ]);

        if (! $dryRun) {
            $this->databaseManager->transaction(function () use ($optOut, $phone, $existing) {
                if ($existing !== null) {
                    $this->optOutRepository->deleteWhere([SmsOptOutDomainObjectAbstract::ID => $optOut->getId()]);

                    return;
                }

                $this->optOutRepository->updateFromArray($optOut->getId(), [SmsOptOutDomainObjectAbstract::PHONE => $phone]);
            });
        }

        return new SmsPhoneFixItemDTO(
            type: 'stop',
            id: $optOut->getId(),
            organizerId: $optOut->getOrganizerId(),
            from: $optOut->getPhone(),
            to: $phone,
            action: $existing !== null ? self::ACTION_DUPLICATE_REMOVED : self::ACTION_RENAMED,
        );
    }
}
