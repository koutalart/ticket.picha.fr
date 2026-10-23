<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Sms;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Sms\DeleteSmsReplyTemplateHandler;
use Illuminate\Http\Response;

class DeleteSmsReplyTemplateAction extends BaseAction
{
    public function __construct(
        private readonly DeleteSmsReplyTemplateHandler $handler,
    ) {}

    public function __invoke(int $organizerId, int $templateId): Response
    {
        $this->isActionAuthorized($organizerId, OrganizerDomainObject::class);

        try {
            $this->handler->handle($organizerId, $templateId);
        } catch (ResourceNotFoundException) {
            return $this->notFoundResponse();
        }

        return $this->deletedResponse();
    }
}
