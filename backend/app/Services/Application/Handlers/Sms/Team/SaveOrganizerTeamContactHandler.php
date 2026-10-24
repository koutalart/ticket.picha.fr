<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms\Team;

use HiEvents\DomainObjects\OrganizerTeamContactDomainObject;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\OrganizerTeamContactException;
use HiEvents\Services\Application\Handlers\Sms\Team\DTO\SaveOrganizerTeamContactDTO;
use HiEvents\Services\Domain\Sms\Team\OrganizerTeamContactService;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;

class SaveOrganizerTeamContactHandler
{
    public function __construct(
        private readonly OrganizerTeamContactService $teamContactService,
        private readonly HtmlPurifierService $purifier,
    ) {}

    /**
     * @throws InvalidSmsPhoneNumberException
     * @throws OrganizerTeamContactException
     */
    public function handle(SaveOrganizerTeamContactDTO $dto): OrganizerTeamContactDomainObject
    {
        $name = $dto->name !== null
            ? trim(strip_tags(html_entity_decode((string) $this->purifier->purify($dto->name), ENT_QUOTES | ENT_HTML5, 'UTF-8')))
            : null;

        return $this->teamContactService->save($dto->organizerId, $dto->phone, $name, $dto->role);
    }
}
