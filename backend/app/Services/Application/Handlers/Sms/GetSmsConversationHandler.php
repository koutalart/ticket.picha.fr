<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\Generated\SmsConversationDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsMessageDomainObjectAbstract;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Application\Handlers\Sms\DTO\SmsConversationDetailDTO;
use HiEvents\Services\Domain\Sms\SmsConversationService;

class GetSmsConversationHandler
{
    public function __construct(
        private readonly SmsConversationRepositoryInterface $conversationRepository,
        private readonly SmsMessageRepositoryInterface $messageRepository,
        private readonly SmsConversationService $conversationService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $organizerId, int $conversationId): SmsConversationDetailDTO
    {
        /** @var SmsConversationDomainObject|null $conversation */
        $conversation = $this->conversationRepository->findFirstWhere([
            SmsConversationDomainObjectAbstract::ID => $conversationId,
            SmsConversationDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($conversation === null) {
            throw new ResourceNotFoundException(__('Conversation not found'));
        }

        $customerOrders = $this->conversationRepository->findOrdersByPhone($organizerId, $conversation->getPhone());
        $attributes = [];

        if ($conversation->getUnreadCount() > 0) {
            $attributes[SmsConversationDomainObjectAbstract::UNREAD_COUNT] = 0;
        }

        $first = $customerOrders->first();
        if ($first !== null && $conversation->getContactName() === null) {
            $name = trim(($first->firstName ?? '').' '.($first->lastName ?? ''));
            if ($name !== '') {
                $attributes[SmsConversationDomainObjectAbstract::CONTACT_NAME] = mb_substr($name, 0, 150);
            }
        }

        if ($attributes !== []) {
            $conversation = $this->conversationRepository->updateFromArray($conversation->getId(), $attributes);
        }

        $messages = $this->messageRepository->findWhere(
            where: [SmsMessageDomainObjectAbstract::SMS_CONVERSATION_ID => $conversation->getId()],
            orderAndDirections: [new OrderAndDirection(SmsMessageDomainObjectAbstract::ID, 'asc')],
        );

        return new SmsConversationDetailDTO(
            conversation: $conversation,
            messages: $messages,
            customerOrders: $customerOrders,
            optedOut: $this->conversationService->isOptedOut($organizerId, $conversation->getPhone()),
        );
    }
}
