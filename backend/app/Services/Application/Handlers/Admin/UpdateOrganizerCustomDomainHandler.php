<?php

namespace HiEvents\Services\Application\Handlers\Admin;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\InvalidCustomDomainException;
use HiEvents\Exceptions\OrganizerNotFoundException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Helper\CustomDomainHelper;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Admin\DTO\UpdateOrganizerCustomDomainDTO;

class UpdateOrganizerCustomDomainHandler
{
    public function __construct(
        private readonly OrganizerRepositoryInterface $organizerRepository,
    )
    {
    }

    /**
     * @throws OrganizerNotFoundException
     * @throws ResourceConflictException
     * @throws InvalidCustomDomainException
     */
    public function handle(UpdateOrganizerCustomDomainDTO $dto): OrganizerDomainObject
    {
        $organizer = $this->organizerRepository->findFirstWhere([
            'id' => $dto->organizerId,
            'account_id' => $dto->accountId,
        ]);

        if ($organizer === null) {
            throw new OrganizerNotFoundException(__('Organizer not found'));
        }

        $domain = CustomDomainHelper::normalize($dto->customDomain);

        if ($domain !== null) {
            $this->assertDomainIsUsable($domain, $organizer->getId());
        }

        return $this->organizerRepository->updateFromArray($organizer->getId(), [
            'custom_domain' => $domain,
        ]);
    }

    /**
     * @throws ResourceConflictException
     * @throws InvalidCustomDomainException
     */
    private function assertDomainIsUsable(string $domain, int $organizerId): void
    {
        if (!CustomDomainHelper::isValid($domain)) {
            throw new InvalidCustomDomainException(__('The domain name is not valid'));
        }

        $platformHost = CustomDomainHelper::normalize(parse_url((string)config('app.frontend_url'), PHP_URL_HOST) ?: null);
        if ($platformHost !== null && ($domain === $platformHost || str_ends_with($domain, '.' . $platformHost))) {
            throw new InvalidCustomDomainException(__('This domain belongs to the platform and cannot be assigned to an organizer'));
        }

        $existing = $this->organizerRepository->findFirstWhere(['custom_domain' => $domain]);
        if ($existing !== null && $existing->getId() !== $organizerId) {
            throw new ResourceConflictException(__('This domain is already assigned to another organizer'));
        }
    }
}
