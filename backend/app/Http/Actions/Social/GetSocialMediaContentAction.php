<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\SocialMediaFileNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Social\StreamSocialMediaHandler;
use Symfony\Component\HttpFoundation\Response;

/**
 * Preview of an imported media for the organizer's team.
 */
class GetSocialMediaContentAction extends BaseAction
{
    public function __construct(
        private readonly StreamSocialMediaHandler $handler,
    ) {}

    public function __invoke(int $organizerId, int $fileId): Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            return $this->handler->handle($fileId, $organizerId);
        } catch (SocialMediaFileNotFoundException) {
            return $this->notFoundResponse();
        }
    }
}
