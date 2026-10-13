<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\QrLinks;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\QrLink\GetQrLinkPosterHandler;
use Illuminate\Http\Response;

class GetQrLinkPosterAction extends BaseAction
{
    public function __construct(
        private readonly GetQrLinkPosterHandler $handler,
    ) {}

    public function __invoke(int $organizerId, int $qrLinkId): Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $png = $this->handler->handle($organizerId, $qrLinkId);
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return response($png)
            ->header('Content-Type', 'image/png')
            ->header('Cache-Control', 'no-store');
    }
}
