<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Enums\SmsFilePriority;
use HiEvents\DomainObjects\Enums\SmsMessageDirection;
use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsConversationDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsMessageDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\SmsFileRejectedException;
use HiEvents\Exceptions\SmsFileUnavailableException;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\PreparedSmsDTO;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Infrastructure\HttpSms\HttpSmsClient;
use HiEvents\Services\Infrastructure\SmsFile\DTO\SmsFileMessageDTO;
use HiEvents\Services\Infrastructure\SmsFile\DTO\SmsFileResultDTO;
use HiEvents\Services\Infrastructure\SmsFile\SmsFileClient;
use Psr\Log\LoggerInterface;

class SmsSendService
{
    public const REQUEST_ID_PREFIX = 'picha-sms-';

    public function __construct(
        private readonly OrganizerSmsSettingRepositoryInterface $settingsRepository,
        private readonly SmsMessageRepositoryInterface $messageRepository,
        private readonly SmsConversationRepositoryInterface $conversationRepository,
        private readonly SmsConversationService $conversationService,
        private readonly HttpSmsClient $httpSmsClient,
        private readonly SmsLineService $lineService,
        private readonly SmsFileClient $fileClient,
        private readonly SmsFileStatusService $fileStatusService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Sends one SMS now: through the central queue as « urgent » when it is enabled, otherwise straight to sms.picha.fr.
     * When the queue cannot take it, the SMS goes straight to sms.picha.fr.
     *
     * @throws SmsGatewayNotConfiguredException
     * @throws SmsRecipientOptedOutException
     * @throws InvalidSmsPhoneNumberException
     * @throws SmsGatewayException
     */
    public function send(SendSmsDTO $sms): SmsMessageDomainObject
    {
        $prepared = $this->prepare($sms);
        $message = $this->record($prepared, SmsMessageStatus::PENDING);

        if ($this->fileClient->isEnabled()) {
            return $this->sendUrgentThroughFile($message, $prepared);
        }

        return $this->sendDirect($message, $prepared);
    }

    /**
     * Checks that the SMS can be sent (configured line, valid number, no STOP) without recording anything but the conversation.
     *
     * @throws SmsGatewayNotConfiguredException
     * @throws SmsRecipientOptedOutException
     * @throws InvalidSmsPhoneNumberException
     */
    public function prepare(SendSmsDTO $sms): PreparedSmsDTO
    {
        $settings = $this->findSettings($sms->organizerId);
        $line = $this->getConfiguredLine($settings);

        $phone = SmsPhoneNormalizer::normalize($sms->phone);
        if ($phone === null) {
            throw new InvalidSmsPhoneNumberException(__('Invalid phone number'));
        }

        if ($this->conversationService->isOptedOut($sms->organizerId, $phone)) {
            throw new SmsRecipientOptedOutException(__('This number replied STOP and can no longer receive SMS from you'));
        }

        $conversation = $this->conversationService->findOrCreate($settings, $phone);
        if ($sms->replyInConversation) {
            $line = $this->lineService->replyLine($settings, $conversation, $line);
        }

        return new PreparedSmsDTO(sms: $sms, phone: $phone, line: $line, conversation: $conversation);
    }

    /**
     * Records the outbound message in its conversation.
     */
    public function record(PreparedSmsDTO $prepared, SmsMessageStatus $status, array $extra = []): SmsMessageDomainObject
    {
        $sms = $prepared->sms;
        $conversation = $prepared->conversation;
        $line = $prepared->line;

        /** @var SmsMessageDomainObject $message */
        $message = $this->messageRepository->create([
            SmsMessageDomainObjectAbstract::SMS_CONVERSATION_ID => $conversation->getId(),
            SmsMessageDomainObjectAbstract::ORGANIZER_ID => $sms->organizerId,
            SmsMessageDomainObjectAbstract::DIRECTION => SmsMessageDirection::OUTBOUND->name,
            SmsMessageDomainObjectAbstract::AUTHOR_TYPE => $sms->authorType->name,
            SmsMessageDomainObjectAbstract::SENT_BY_USER_ID => $sms->sentByUserId,
            SmsMessageDomainObjectAbstract::CONTENT => $sms->content,
            SmsMessageDomainObjectAbstract::STATUS => $status->name,
            SmsMessageDomainObjectAbstract::AUTO_REPLY_TO_MESSAGE_ID => $sms->autoReplyToMessageId,
            SmsMessageDomainObjectAbstract::AUTO_REPLY_TYPE => $sms->autoReplyType?->name,
            SmsMessageDomainObjectAbstract::LINE_SETTINGS_ID => $line->getId(),
            SmsMessageDomainObjectAbstract::IS_SUPERVISION => $sms->isSupervision,
            SmsMessageDomainObjectAbstract::AWAITS_REPLY => $sms->awaitsReply,
            ...$extra,
        ]);

        $this->messageRepository->updateFromArray($message->getId(), [
            SmsMessageDomainObjectAbstract::REQUEST_ID => self::REQUEST_ID_PREFIX.$message->getId(),
        ]);

        $this->conversationService->recordActivity(
            $conversation,
            SmsMessageDirection::OUTBOUND,
            $sms->content,
            markUnread: false,
            lineSettingsId: $line->getId(),
        );
        if (! $sms->keepDraft) {
            $this->conversationRepository->updateFromArray($conversation->getId(), [
                SmsConversationDomainObjectAbstract::DRAFT_REPLY => null,
                SmsConversationDomainObjectAbstract::DRAFT_SOURCE => null,
                SmsConversationDomainObjectAbstract::DRAFT_GENERATED_AT => null,
            ]);
        }

        return $message;
    }

    /**
     * @throws SmsGatewayException
     */
    private function sendUrgentThroughFile(SmsMessageDomainObject $message, PreparedSmsDTO $prepared): SmsMessageDomainObject
    {
        $item = new SmsFileMessageDTO(
            organizerId: $prepared->sms->organizerId,
            from: (string) $prepared->line->getFromNumber(),
            to: $prepared->phone,
            text: $prepared->sms->content,
            priority: SmsFilePriority::URGENT,
            ref: 'msg:'.$message->getId(),
        );

        try {
            $result = $this->enqueueUrgent($item);
        } catch (SmsFileUnavailableException|SmsFileRejectedException $exception) {
            $this->logger->warning('SMS queue unavailable for an urgent SMS, sent straight to PICHA SMS', [
                'organizer_id' => $prepared->sms->organizerId,
                'sms_message_id' => $message->getId(),
                'error' => $exception->getMessage(),
            ]);

            return $this->sendDirect($message, $prepared);
        }

        $message = $this->fileStatusService->applyToMessage($message, $result);

        if ($result->state->messageStatus() === SmsMessageStatus::FAILED) {
            throw new SmsGatewayException($result->reason ?? __('The SMS could not be sent'));
        }

        return $message;
    }

    /**
     * A timeout can hide an SMS the queue did take: the same ref is sent once more, the queue answers « doublon ».
     *
     * @throws SmsFileUnavailableException
     * @throws SmsFileRejectedException
     */
    private function enqueueUrgent(SmsFileMessageDTO $item): SmsFileResultDTO
    {
        try {
            return $this->fileClient->enqueue($item);
        } catch (SmsFileUnavailableException) {
            return $this->fileClient->enqueue($item);
        }
    }

    /**
     * @throws SmsGatewayException
     */
    private function sendDirect(SmsMessageDomainObject $message, PreparedSmsDTO $prepared): SmsMessageDomainObject
    {
        try {
            $providerId = $this->httpSmsClient->sendMessage(
                apiKey: (string) $prepared->line->getApiKey(),
                from: (string) $prepared->line->getFromNumber(),
                to: $prepared->phone,
                content: $prepared->sms->content,
                requestId: self::REQUEST_ID_PREFIX.$message->getId(),
            );
        } catch (SmsGatewayException $exception) {
            $this->messageRepository->updateFromArray($message->getId(), [
                SmsMessageDomainObjectAbstract::STATUS => SmsMessageStatus::FAILED->name,
                SmsMessageDomainObjectAbstract::ERROR_MESSAGE => mb_substr($exception->getMessage(), 0, 500),
            ]);

            throw $exception;
        }

        return $this->messageRepository->updateFromArray($message->getId(), [
            SmsMessageDomainObjectAbstract::PROVIDER_MESSAGE_ID => $providerId,
        ]);
    }

    /**
     * @throws SmsGatewayNotConfiguredException
     */
    private function findSettings(int $organizerId): OrganizerSmsSettingDomainObject
    {
        /** @var OrganizerSmsSettingDomainObject|null $settings */
        $settings = $this->settingsRepository->findFirstWhere([
            OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($settings === null) {
            throw new SmsGatewayNotConfiguredException(__('SMS sending is not configured. Add your PICHA SMS API key and sending number in Settings.'));
        }

        return $settings;
    }

    /**
     * The line the organizer sends with: its own settings, or the line it shares with other organizers.
     *
     * @throws SmsGatewayNotConfiguredException
     */
    private function getConfiguredLine(OrganizerSmsSettingDomainObject $settings): OrganizerSmsSettingDomainObject
    {
        $line = $this->lineService->resolveLine($settings);

        if (! $line->getApiKey() || ! $line->getFromNumber()) {
            throw new SmsGatewayNotConfiguredException(__('SMS sending is not configured. Add your PICHA SMS API key and sending number in Settings.'));
        }

        return $line;
    }
}
