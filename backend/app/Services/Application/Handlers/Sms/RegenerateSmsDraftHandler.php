<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\Generated\SmsConversationDomainObjectAbstract;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SmsAssistantFailedException;
use HiEvents\Exceptions\SmsAssistantLimitReachedException;
use HiEvents\Exceptions\SmsAssistantUnavailableException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Services\Domain\Sms\SmsAssistantService;

class RegenerateSmsDraftHandler
{
    public function __construct(
        private readonly SmsConversationRepositoryInterface $conversationRepository,
        private readonly SmsAssistantService $assistantService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws SmsAssistantUnavailableException
     * @throws SmsAssistantLimitReachedException
     * @throws SmsAssistantFailedException
     * @throws SmsRecipientOptedOutException
     */
    public function handle(int $organizerId, int $conversationId): SmsConversationDomainObject
    {
        /** @var SmsConversationDomainObject|null $conversation */
        $conversation = $this->conversationRepository->findFirstWhere([
            SmsConversationDomainObjectAbstract::ID => $conversationId,
            SmsConversationDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($conversation === null) {
            throw new ResourceNotFoundException(__('Conversation not found'));
        }

        return $this->assistantService->regenerate($conversation);
    }
}
