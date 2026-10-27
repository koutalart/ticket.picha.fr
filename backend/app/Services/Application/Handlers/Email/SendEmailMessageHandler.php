<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Email;

use HiEvents\DomainObjects\EmailMessageDomainObject;
use HiEvents\Exceptions\EmailRecipientNotAllowedException;
use HiEvents\Exceptions\EmailSendFailedException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Services\Domain\Email\Inbox\DTO\ComposeEmailDTO;
use HiEvents\Services\Domain\Email\Inbox\EmailComposerService;

class SendEmailMessageHandler
{
    public function __construct(
        private readonly EmailComposerService $composerService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws EmailRecipientNotAllowedException
     * @throws EmailSendFailedException
     */
    public function handle(ComposeEmailDTO $compose): EmailMessageDomainObject
    {
        return $this->composerService->send($compose);
    }
}
