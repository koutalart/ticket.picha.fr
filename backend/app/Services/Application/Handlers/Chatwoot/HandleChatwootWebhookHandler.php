<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Chatwoot;

use HiEvents\DomainObjects\Generated\SmsConversationDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsMessageDomainObjectAbstract;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\Exceptions\ChatwootWebhookUnauthorizedException;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Application\Handlers\Sms\SendSmsReplyHandler;
use HiEvents\Services\Domain\Chatwoot\ChatwootSmsMirrorService;
use HiEvents\Services\Domain\Chatwoot\ChatwootUnknownNumberReplyService;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;

/**
 * A reply typed by an agent in the « SMS PICHA » inbox of Chatwoot is sent as an SMS by PICHA. Messages that PICHA
 * itself copied into Chatwoot (incoming SMS, replies of Anicka, campaigns) come back here too and are ignored.
 */
class HandleChatwootWebhookHandler
{
    public function __construct(
        private readonly Repository $config,
        private readonly Cache $cache,
        private readonly SmsConversationRepositoryInterface $conversationRepository,
        private readonly SmsMessageRepositoryInterface $messageRepository,
        private readonly SendSmsReplyHandler $replyHandler,
        private readonly ChatwootSmsMirrorService $mirrorService,
        private readonly LoggerInterface $logger,
        private readonly ChatwootUnknownNumberReplyService $unknownNumberReplyService,
    ) {}

    /**
     * @throws ChatwootWebhookUnauthorizedException
     */
    public function handle(string $webhookToken, array $event): void
    {
        $expected = (string) $this->config->get('services.chatwoot.webhook_token');
        if ($expected === '' || ! hash_equals($expected, $webhookToken)) {
            throw new ChatwootWebhookUnauthorizedException('Unknown Chatwoot webhook');
        }

        if (! $this->isAgentReply($event)) {
            return;
        }

        $chatwootConversationId = (int) ($event['conversation']['id'] ?? 0);
        $chatwootMessageId = (int) ($event['id'] ?? 0);
        $content = trim((string) ($event['content'] ?? ''));

        if ($this->cache->pull(ChatwootSmsMirrorService::pendingKey($chatwootConversationId, $content)) !== null) {
            return;
        }

        if ($this->messageRepository->findFirstWhere([SmsMessageDomainObjectAbstract::CHATWOOT_MESSAGE_ID => $chatwootMessageId]) !== null) {
            return;
        }

        /** @var SmsConversationDomainObject|null $conversation */
        $conversation = $this->conversationRepository->findFirstWhere([
            SmsConversationDomainObjectAbstract::CHATWOOT_CONVERSATION_ID => $chatwootConversationId,
        ]);
        if ($conversation === null) {
            $this->replyToUnknownNumber($chatwootConversationId, $chatwootMessageId, $content);

            return;
        }

        try {
            $message = ChatwootSmsMirrorService::withoutMirroring(fn () => $this->replyHandler->handle(
                organizerId: $conversation->getOrganizerId(),
                conversationId: $conversation->getId(),
                content: $content,
                userId: null,
            ));
        } catch (SmsRecipientOptedOutException) {
            $this->mirrorService->postNote($chatwootConversationId, __('Not sent: this number asked not to receive SMS any more.'));

            return;
        } catch (SmsGatewayNotConfiguredException|SmsGatewayException|InvalidSmsPhoneNumberException|ResourceNotFoundException $exception) {
            $this->logger->warning('Chatwoot reply not sent as SMS', [
                'sms_conversation_id' => $conversation->getId(),
                'error' => $exception->getMessage(),
            ]);
            $this->mirrorService->postNote($chatwootConversationId, __('SMS not sent: :error', ['error' => $exception->getMessage()]));

            return;
        }

        $this->messageRepository->updateFromArray($message->getId(), [
            SmsMessageDomainObjectAbstract::CHATWOOT_MESSAGE_ID => $chatwootMessageId,
        ]);
    }

    private function replyToUnknownNumber(int $chatwootConversationId, int $chatwootMessageId, string $content): void
    {
        try {
            if (! $this->unknownNumberReplyService->reply($chatwootConversationId, $chatwootMessageId, $content)) {
                $this->mirrorService->postNote($chatwootConversationId, __('Not sent: this conversation is not linked to a PICHA SMS conversation.'));
            }
        } catch (SmsRecipientOptedOutException) {
            $this->mirrorService->postNote($chatwootConversationId, __('Not sent: this number asked not to receive SMS any more.'));
        } catch (SmsGatewayNotConfiguredException|SmsGatewayException $exception) {
            $this->logger->warning('Chatwoot reply to a number without conversation not sent as SMS', [
                'chatwoot_conversation_id' => $chatwootConversationId,
                'error' => $exception->getMessage(),
            ]);
            $this->mirrorService->postNote($chatwootConversationId, __('SMS not sent: :error', ['error' => $exception->getMessage()]));
        }
    }

    private function isAgentReply(array $event): bool
    {
        $senderType = strtolower((string) ($event['sender']['type'] ?? ''));
        $gatewayUserId = (int) $this->config->get('services.chatwoot.gateway_user_id');

        return ($event['event'] ?? null) === 'message_created'
            && ($event['message_type'] ?? null) === 'outgoing'
            && ($event['private'] ?? true) === false
            && (int) ($event['inbox']['id'] ?? 0) === (int) $this->config->get('services.chatwoot.sms_inbox_id')
            && $senderType === 'user'
            && ($gatewayUserId === 0 || (int) ($event['sender']['id'] ?? 0) !== $gatewayUserId)
            && trim((string) ($event['content'] ?? '')) !== '';
    }
}
