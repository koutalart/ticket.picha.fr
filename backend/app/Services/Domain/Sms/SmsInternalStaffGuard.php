<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Generated\OrganizerDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrganizerTeamContactDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerTeamContactRepositoryInterface;
use Illuminate\Config\Repository as ConfigRepository;

/**
 * The PICHA staff are the team contacts of the internal organizer (HTTPSMS_INTERNAL_ORGANIZER_ID, « Anicka »).
 * An SMS exchanged with one of them is never stored with a client organizer, and they never join a client team.
 * Without an internal organizer configured, nothing changes.
 */
class SmsInternalStaffGuard
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly OrganizerTeamContactRepositoryInterface $teamContactRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
    ) {}

    public function internalOrganizerId(): ?int
    {
        $id = (int) $this->config->get('services.httpsms.internal_organizer_id');

        return $id > 0 ? $id : null;
    }

    public function isStaffPhone(string $phone): bool
    {
        $internalOrganizerId = $this->internalOrganizerId();
        $normalized = SmsPhoneNormalizer::normalize($phone);
        if ($internalOrganizerId === null || $normalized === null) {
            return false;
        }

        return $this->teamContactRepository->findWhereIn(
            OrganizerTeamContactDomainObjectAbstract::PHONE,
            SmsPhoneNormalizer::equivalents($normalized),
            [OrganizerTeamContactDomainObjectAbstract::ORGANIZER_ID => $internalOrganizerId],
        )->isNotEmpty();
    }

    /**
     * Whether this number of the PICHA staff is refused in the team of the given organizer (any organizer but the internal one).
     */
    public function isStaffPhoneForClient(int $organizerId, string $phone): bool
    {
        $internalOrganizerId = $this->internalOrganizerId();

        return $internalOrganizerId !== null && $organizerId !== $internalOrganizerId && $this->isStaffPhone($phone);
    }

    /**
     * The internal organizer an SMS with this number must be stored for instead of the given organizer, null when it stays
     * with the given organizer.
     */
    public function rerouteTarget(int $organizerId, string $phone): ?OrganizerDomainObject
    {
        if (! $this->isStaffPhoneForClient($organizerId, $phone)) {
            return null;
        }

        return $this->organizerRepository->findFirstWhere([
            OrganizerDomainObjectAbstract::ID => $this->internalOrganizerId(),
        ]);
    }
}
