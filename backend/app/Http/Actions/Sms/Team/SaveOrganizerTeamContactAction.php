<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms\Team;

use HiEvents\DomainObjects\Enums\OrganizerTeamContactRole;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\OrganizerTeamContactException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Sms\SaveOrganizerTeamContactRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Sms\OrganizerTeamContactResource;
use HiEvents\Services\Application\Handlers\Sms\Team\DTO\SaveOrganizerTeamContactDTO;
use HiEvents\Services\Application\Handlers\Sms\Team\SaveOrganizerTeamContactHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class SaveOrganizerTeamContactAction extends BaseAction
{
    public function __construct(
        private readonly SaveOrganizerTeamContactHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(SaveOrganizerTeamContactRequest $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $contact = $this->handler->handle(new SaveOrganizerTeamContactDTO(
                organizerId: $organizerId,
                phone: $request->validated('phone'),
                name: $request->validated('name'),
                role: OrganizerTeamContactRole::from($request->validated('role')),
            ));
        } catch (InvalidSmsPhoneNumberException|OrganizerTeamContactException $exception) {
            throw ValidationException::withMessages(['phone' => $exception->getMessage()]);
        }

        return $this->resourceResponse(OrganizerTeamContactResource::class, $contact, ResponseCodes::HTTP_CREATED);
    }
}
