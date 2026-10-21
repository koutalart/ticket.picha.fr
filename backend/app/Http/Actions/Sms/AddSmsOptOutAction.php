<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Sms\AddSmsOptOutRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Sms\SmsOptOutResource;
use HiEvents\Services\Application\Handlers\Sms\AddSmsOptOutHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AddSmsOptOutAction extends BaseAction
{
    public function __construct(
        private readonly AddSmsOptOutHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(AddSmsOptOutRequest $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $optOut = $this->handler->handle($organizerId, $request->validated('phone'));
        } catch (InvalidSmsPhoneNumberException $exception) {
            throw ValidationException::withMessages(['phone' => $exception->getMessage()]);
        }

        return $this->resourceResponse(SmsOptOutResource::class, $optOut, ResponseCodes::HTTP_CREATED);
    }
}
