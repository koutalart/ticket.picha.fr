<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use HiEvents\Exceptions\SocialMediaFileNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Social\StreamSocialMediaHandler;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reached only through the temporary signed URLs given to Meta (signed:relative middleware).
 */
class GetSocialMediaActionPublic extends BaseAction
{
    public function __construct(
        private readonly StreamSocialMediaHandler $handler,
    ) {}

    public function __invoke(int $mediaId): Response
    {
        try {
            return $this->handler->handle($mediaId, null);
        } catch (SocialMediaFileNotFoundException) {
            return $this->notFoundResponse();
        }
    }
}
