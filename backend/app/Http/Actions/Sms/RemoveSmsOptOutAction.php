<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Sms\RemoveSmsOptOutHandler;
use Illuminate\Http\Response;

class RemoveSmsOptOutAction extends BaseAction
{
    public function __construct(
        private readonly RemoveSmsOptOutHandler $handler,
    ) {}

    public function __invoke(int $organizerId, int $optOutId): Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $this->handler->handle($organizerId, $optOutId);
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->deletedResponse();
    }
}
