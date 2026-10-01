<?php

namespace HiEvents\Services\Application\Handlers\Admin;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\OrganizerNotFoundException;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Admin\DTO\UpdateOrganizerSiteContentDTO;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;

class UpdateOrganizerSiteContentHandler
{
    public const HTML_FIELDS = ['story', 'vision'];

    public function __construct(
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly HtmlPurifierService          $htmlPurifier,
    )
    {
    }

    /**
     * @throws OrganizerNotFoundException
     */
    public function handle(UpdateOrganizerSiteContentDTO $dto): OrganizerDomainObject
    {
        $organizer = $this->organizerRepository->findFirstWhere([
            'id' => $dto->organizerId,
            'account_id' => $dto->accountId,
        ]);

        if ($organizer === null) {
            throw new OrganizerNotFoundException(__('Organizer not found'));
        }

        $content = $dto->siteContent;

        foreach (self::HTML_FIELDS as $field) {
            if (isset($content[$field])) {
                $content[$field] = $this->htmlPurifier->purify($content[$field]);
            }
        }

        return $this->organizerRepository->updateFromArray($organizer->getId(), [
            'site_content' => $content,
        ]);
    }
}
