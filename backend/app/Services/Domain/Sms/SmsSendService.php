<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Enums\SmsMessageDirection;
use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsConversationDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsMessageDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Infrastructure\HttpSms\HttpSmsClient;

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
    ) {}

    /**
     * @throws SmsGatewayNotConfiguredException
     * @throws SmsRecipientOptedOutException
     * @throws InvalidSmsPhoneNumberException
     * @throws SmsGatewayException
     */
    public function send(SendSmsDTO $sms): SmsMessageDomainObject
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

        /** @var SmsMessageDomainObject $message */
        $message = $this->messageRepository->create([
            SmsMessageDomainObjectAbstract::SMS_CONVERSATION_ID => $conversation->getId(),
            SmsMessageDomainObjectAbstract::ORGANIZER_ID => $sms->organizerId,
            SmsMessageDomainObjectAbstract::DIRECTION => SmsMessageDirection::OUTBOUND->name,
            SmsMessageDomainObjectAbstract::AUTHOR_TYPE => $sms->authorType->name,
            SmsMessageDomainObjectAbstract::SENT_BY_USER_ID => $sms->sentByUserId,
            SmsMessageDomainObjectAbstract::CONTENT => $sms->content,
            SmsMessageDomainObjectAbstract::STATUS => SmsMessageStatus::PENDING->name,
        ]);

        $requestId = self::REQUEST_ID_PREFIX.$message->getId();
        $this->messageRepository->updateFromArray($message->getId(), [
            SmsMessageDomainObjectAbstract::REQUEST_ID => $requestId,
        ]);

        $this->conversationService->recordActivity($conversation, SmsMessageDirection::OUTBOUND, $sms->content, markUnread: false);
        $this->conversationRepository->updateFromArray($conversation->getId(), [
            SmsConversationDomainObjectAbstract::DRAFT_REPLY => null,
            SmsConversationDomainObjectAbstract::DRAFT_SOURCE => null,
            SmsConversationDomainObjectAbstract::DRAFT_GENERATED_AT => null,
        ]);

        try {
            $providerId = $this->httpSmsClient->sendMessage(
                apiKey: (string) $line->getApiKey(),
                from: (string) $line->getFromNumber(),
                to: $phone,
                content: $sms->content,
                requestId: $requestId,
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
