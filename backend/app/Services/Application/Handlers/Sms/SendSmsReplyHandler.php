<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\Enums\SmsDraftSource;
use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\Generated\SmsConversationDomainObjectAbstract;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\SmsSendService;

class SendSmsReplyHandler
{
    public function __construct(
        private readonly SmsConversationRepositoryInterface $conversationRepository,
        private readonly SmsSendService $sendService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws SmsGatewayNotConfiguredException
     * @throws SmsRecipientOptedOutException
     * @throws InvalidSmsPhoneNumberException
     * @throws SmsGatewayException
     */
    public function handle(int $organizerId, int $conversationId, string $content, int $userId): SmsMessageDomainObject
    {
        /** @var SmsConversationDomainObject|null $conversation */
        $conversation = $this->conversationRepository->findFirstWhere([
            SmsConversationDomainObjectAbstract::ID => $conversationId,
            SmsConversationDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($conversation === null) {
            throw new ResourceNotFoundException(__('Conversation not found'));
        }

        $content = trim($content);

        return $this->sendService->send(new SendSmsDTO(
            organizerId: $organizerId,
            phone: $conversation->getPhone(),
            content: $content,
            authorType: $this->isUnchangedAgentDraft($conversation, $content)
                ? SmsMessageAuthorType::AGENT
                : SmsMessageAuthorType::HUMAN,
            sentByUserId: $userId,
        ));
    }

    private function isUnchangedAgentDraft(SmsConversationDomainObject $conversation, string $content): bool
    {
        return $conversation->getDraftSource() === SmsDraftSource::AGENT->name
            && $conversation->getDraftReply() !== null
            && trim(str_replace("\r\n", "\n", $conversation->getDraftReply())) === str_replace("\r\n", "\n", $content);
    }
}
