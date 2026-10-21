<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\Generated\SmsConversationDomainObjectAbstract;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\Status\SmsConversationStatus;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;

class UpdateSmsConversationStatusHandler
{
    public function __construct(
        private readonly SmsConversationRepositoryInterface $conversationRepository,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(int $organizerId, int $conversationId, SmsConversationStatus $status): SmsConversationDomainObject
    {
        $where = [
            SmsConversationDomainObjectAbstract::ID => $conversationId,
            SmsConversationDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ];

        if ($this->conversationRepository->findFirstWhere($where) === null) {
            throw new ResourceNotFoundException(__('Conversation not found'));
        }

        return $this->conversationRepository->updateByIdWhere(
            id: $conversationId,
            attributes: [
                SmsConversationDomainObjectAbstract::STATUS => $status->name,
                ...($status === SmsConversationStatus::NEEDS_HUMAN ? [] : [SmsConversationDomainObjectAbstract::NEEDS_HUMAN_REASON => null]),
            ],
            where: $where,
        );
    }
}
