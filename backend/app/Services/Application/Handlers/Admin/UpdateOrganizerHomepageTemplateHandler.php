<?php

namespace HiEvents\Services\Application\Handlers\Admin;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\OrganizerNotFoundException;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Admin\DTO\UpdateOrganizerHomepageTemplateDTO;

class UpdateOrganizerHomepageTemplateHandler
{
    public function __construct(
        private readonly OrganizerRepositoryInterface $organizerRepository,
    )
    {
    }

    /**
     * @throws OrganizerNotFoundException
     */
    public function handle(UpdateOrganizerHomepageTemplateDTO $dto): OrganizerDomainObject
    {
        $organizer = $this->organizerRepository->findFirstWhere([
            'id' => $dto->organizerId,
            'account_id' => $dto->accountId,
        ]);

        if ($organizer === null) {
            throw new OrganizerNotFoundException(__('Organizer not found'));
        }

        return $this->organizerRepository->updateFromArray($organizer->getId(), [
            'homepage_template' => $dto->homepageTemplate->value,
        ]);
    }
}
