<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\SocialConnectionNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Social\DisconnectSocialConnectionHandler;
use Illuminate\Http\Response;

class DisconnectSocialConnectionAction extends BaseAction
{
    public function __construct(
        private readonly DisconnectSocialConnectionHandler $handler,
    ) {}

    public function __invoke(int $organizerId): Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $this->handler->handle($this->getAuthenticatedAccountId(), $organizerId);
        } catch (SocialConnectionNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->deletedResponse();
    }
}
