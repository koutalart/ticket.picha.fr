<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Social;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\MetaOAuthException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Social\ConnectMetaPageRequest;
use HiEvents\Resources\Social\SocialConnectionResource;
use HiEvents\Services\Application\Handlers\Social\ConnectMetaPageHandler;
use HiEvents\Services\Application\Handlers\Social\DTO\SocialConnectionStatusDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ConnectMetaPageAction extends BaseAction
{
    public function __construct(
        private readonly ConnectMetaPageHandler $handler,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(ConnectMetaPageRequest $request, int $organizerId): JsonResponse
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $connection = $this->handler->handle(
                $this->getAuthenticatedAccountId(),
                $organizerId,
                (string) $request->validated('selection_key'),
                (string) $request->validated('page_id'),
            );
        } catch (MetaOAuthException $exception) {
            throw ValidationException::withMessages(['page_id' => $exception->getMessage()]);
        }

        return $this->resourceResponse(
            SocialConnectionResource::class,
            new SocialConnectionStatusDTO(available: true, connection: $connection),
        );
    }
}
