<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Sms\DeleteSmsLineKeywordHandler;
use Illuminate\Http\Response;

class DeleteSmsLineKeywordAction extends BaseAction
{
    public function __construct(
        private readonly DeleteSmsLineKeywordHandler $handler,
    ) {}

    public function __invoke(int $organizerId, int $keywordId): Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $this->handler->handle($organizerId, $keywordId);
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->deletedResponse();
    }
}
