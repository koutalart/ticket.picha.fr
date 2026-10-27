<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Email;

use HiEvents\DomainObjects\EmailThreadDomainObject;
use HiEvents\DomainObjects\Generated\EmailThreadDomainObjectAbstract;
use HiEvents\DomainObjects\Status\EmailThreadStatus;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\EmailThreadRepositoryInterface;

class UpdateEmailThreadStatusHandler
{
    public function __construct(
        private readonly EmailThreadRepositoryInterface $threadRepository,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $organizerId, int $threadId, EmailThreadStatus $status): EmailThreadDomainObject
    {
        /** @var EmailThreadDomainObject|null $thread */
        $thread = $this->threadRepository->findFirstWhere([
            EmailThreadDomainObjectAbstract::ID => $threadId,
            EmailThreadDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($thread === null) {
            throw new ResourceNotFoundException(__('Conversation not found'));
        }

        return $this->threadRepository->updateFromArray($thread->getId(), [
            EmailThreadDomainObjectAbstract::STATUS => $status->name,
        ]);
    }
}
