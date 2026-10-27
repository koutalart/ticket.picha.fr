<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Email;

use HiEvents\DomainObjects\EmailThreadDomainObject;
use HiEvents\DomainObjects\Generated\EmailMessageDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\EmailThreadDomainObjectAbstract;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Interfaces\EmailMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\EmailThreadRepositoryInterface;
use HiEvents\Services\Application\Handlers\Email\DTO\EmailThreadDetailDTO;

class GetEmailThreadHandler
{
    public function __construct(
        private readonly EmailThreadRepositoryInterface $threadRepository,
        private readonly EmailMessageRepositoryInterface $messageRepository,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $organizerId, int $threadId): EmailThreadDetailDTO
    {
        /** @var EmailThreadDomainObject|null $thread */
        $thread = $this->threadRepository->findFirstWhere([
            EmailThreadDomainObjectAbstract::ID => $threadId,
            EmailThreadDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($thread === null) {
            throw new ResourceNotFoundException(__('Conversation not found'));
        }

        if ($thread->getUnreadCount() > 0) {
            $thread = $this->threadRepository->updateFromArray($thread->getId(), [
                EmailThreadDomainObjectAbstract::UNREAD_COUNT => 0,
            ]);
        }

        $messages = $this->messageRepository->findWhere(
            where: [
                EmailMessageDomainObjectAbstract::EMAIL_THREAD_ID => $thread->getId(),
                EmailMessageDomainObjectAbstract::ORGANIZER_ID => $organizerId,
            ],
            orderAndDirections: [new OrderAndDirection(EmailMessageDomainObjectAbstract::ID, 'asc')],
        );

        return new EmailThreadDetailDTO(
            thread: $thread,
            messages: $messages,
            customerOrders: $this->threadRepository->findOrdersByEmail($organizerId, $thread->getEmail()),
            unsubscribed: $this->threadRepository->isUnsubscribedRecipient($organizerId, $thread->getEmail()),
        );
    }
}
