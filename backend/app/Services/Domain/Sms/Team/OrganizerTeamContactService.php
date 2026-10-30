<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms\Team;

use HiEvents\DomainObjects\Enums\OrganizerTeamContactRole;
use HiEvents\DomainObjects\Generated\OrganizerTeamContactDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsConversationDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerTeamContactDomainObject;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\OrganizerTeamContactException;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Interfaces\OrganizerTeamContactRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * The people who run an organizer (role ORGANIZER) or follow it in copy (role COPY). Their SMS are never answered by
 * the customer assistant, and their conversation with the organizer is marked « Équipe ».
 */
class OrganizerTeamContactService
{
    public const MAX_NAME_LENGTH = 150;

    public function __construct(
        private readonly OrganizerTeamContactRepositoryInterface $contactRepository,
        private readonly SmsConversationRepositoryInterface $conversationRepository,
        private readonly PlatformSupervisorService $supervisorService,
    ) {}

    /**
     * @return Collection<int, OrganizerTeamContactDomainObject>
     */
    public function list(int $organizerId, ?OrganizerTeamContactRole $role = null): Collection
    {
        return $this->contactRepository
            ->findWhere(array_filter([
                OrganizerTeamContactDomainObjectAbstract::ORGANIZER_ID => $organizerId,
                OrganizerTeamContactDomainObjectAbstract::ROLE => $role?->name,
            ]))
            ->sortBy(fn (OrganizerTeamContactDomainObject $contact) => [
                $contact->getRole() === OrganizerTeamContactRole::ORGANIZER->name ? 0 : 1,
                $contact->getId(),
            ])
            ->values();
    }

    /**
     * Adds the number to the team, or updates its name and role when it already is in it.
     *
     * @throws InvalidSmsPhoneNumberException
     * @throws OrganizerTeamContactException
     */
    public function save(int $organizerId, string $phone, ?string $name, OrganizerTeamContactRole $role): OrganizerTeamContactDomainObject
    {
        $normalized = $this->normalize($phone);

        if ($this->supervisorService->isSupervisor($normalized)) {
            throw new OrganizerTeamContactException(__('This number belongs to the platform supervision and cannot join a team'));
        }

        $name = trim((string) $name);
        $attributes = [
            OrganizerTeamContactDomainObjectAbstract::NAME => $name !== '' ? mb_substr($name, 0, self::MAX_NAME_LENGTH) : null,
            OrganizerTeamContactDomainObjectAbstract::ROLE => $role->name,
        ];
        $where = [
            OrganizerTeamContactDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            OrganizerTeamContactDomainObjectAbstract::PHONE => $normalized,
        ];

        /** @var OrganizerTeamContactDomainObject|null $existing */
        $existing = $this->contactRepository->findFirstWhere($where);
        $contact = $existing !== null
            ? $this->contactRepository->updateFromArray($existing->getId(), $attributes)
            : $this->contactRepository->create([...$where, ...$attributes]);

        $this->markConversation($organizerId, $normalized, true, $contact->getName());

        return $contact;
    }

    /**
     * @throws OrganizerTeamContactException
     */
    public function remove(int $organizerId, int $contactId): void
    {
        /** @var OrganizerTeamContactDomainObject|null $contact */
        $contact = $this->contactRepository->findFirstWhere([
            OrganizerTeamContactDomainObjectAbstract::ID => $contactId,
            OrganizerTeamContactDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($contact === null) {
            throw new OrganizerTeamContactException(__('This team contact does not exist'));
        }

        $this->contactRepository->deleteById($contact->getId());
        $this->markConversation($organizerId, $contact->getPhone(), false);
    }

    /**
     * @throws InvalidSmsPhoneNumberException
     * @throws OrganizerTeamContactException
     */
    public function removeByPhone(int $organizerId, string $phone): void
    {
        /** @var OrganizerTeamContactDomainObject|null $contact */
        $contact = $this->contactRepository->findFirstWhere([
            OrganizerTeamContactDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            OrganizerTeamContactDomainObjectAbstract::PHONE => $this->normalize($phone),
        ]);

        if ($contact === null) {
            throw new OrganizerTeamContactException(__('This team contact does not exist'));
        }

        $this->remove($organizerId, $contact->getId());
    }

    public function find(int $organizerId, string $phone): ?OrganizerTeamContactDomainObject
    {
        return $this->contactRepository->findFirstWhere([
            OrganizerTeamContactDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            OrganizerTeamContactDomainObjectAbstract::PHONE => $phone,
        ]);
    }

    /**
     * The team contacts with this number among the given organizers, also when the number was saved with +33.
     *
     * @param  array<int, int>  $organizerIds
     * @return Collection<int, OrganizerTeamContactDomainObject>
     */
    public function findForPhone(array $organizerIds, string $phone): Collection
    {
        if ($organizerIds === []) {
            return collect();
        }

        return $this->contactRepository->findWhereIn(
            OrganizerTeamContactDomainObjectAbstract::PHONE,
            SmsPhoneNormalizer::equivalents($phone),
            [[OrganizerTeamContactDomainObjectAbstract::ORGANIZER_ID, 'in', array_values($organizerIds)]],
        );
    }

    public function markConversation(int $organizerId, string $phone, bool $isTeam, ?string $name = null): void
    {
        $attributes = [SmsConversationDomainObjectAbstract::IS_TEAM => $isTeam];
        if ($isTeam && $name !== null) {
            $attributes[SmsConversationDomainObjectAbstract::CONTACT_NAME] = $name;
        }

        $this->conversationRepository->updateWhere($attributes, [
            SmsConversationDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            SmsConversationDomainObjectAbstract::PHONE => $phone,
        ]);
    }

    /**
     * @throws InvalidSmsPhoneNumberException
     */
    private function normalize(string $phone): string
    {
        $normalized = SmsPhoneNormalizer::normalize($phone);
        if ($normalized === null) {
            throw new InvalidSmsPhoneNumberException(__('Invalid phone number'));
        }

        return $normalized;
    }
}
