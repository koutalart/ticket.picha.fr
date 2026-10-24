<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Sms;

use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Http\Request\Sms\UpdateOrganizerSmsSettingsRequest;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Services\Application\Handlers\Sms\DTO\OrganizerSmsSettingsViewDTO;
use HiEvents\Services\Application\Handlers\Sms\DTO\UpdateOrganizerSmsSettingsDTO;
use HiEvents\Services\Domain\LiveChat\LiveChatReplyGenerator;
use HiEvents\Services\Domain\Sms\SmsAssistantReplyGenerator;
use HiEvents\Services\Domain\Sms\SmsAssistantService;
use HiEvents\Services\Domain\Sms\SmsSettingsService;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;

class UpdateOrganizerSmsSettingsHandler
{
    public function __construct(
        private readonly SmsSettingsService $settingsService,
        private readonly OrganizerSmsSettingRepositoryInterface $settingsRepository,
        private readonly SmsAssistantService $assistantService,
        private readonly HtmlPurifierService $purifier,
    ) {}

    /**
     * @throws InvalidSmsPhoneNumberException
     */
    public function handle(UpdateOrganizerSmsSettingsDTO $dto): OrganizerSmsSettingsViewDTO
    {
        $settings = $this->settingsService->getOrCreate($dto->accountId, $dto->organizerId);
        $attributes = [];
        $usesSharedLine = $settings->getSharedLineSettingsId() !== null;

        if ($dto->wasProvided('apiKey') && ! $usesSharedLine) {
            $apiKey = trim((string) $dto->apiKey);
            $attributes[OrganizerSmsSettingDomainObjectAbstract::API_KEY] = $apiKey !== '' ? $apiKey : null;
        }

        if ($dto->wasProvided('fromNumber') && ! $usesSharedLine) {
            $attributes[OrganizerSmsSettingDomainObjectAbstract::FROM_NUMBER] = $this->normalizeFromNumber($dto->fromNumber);
        }

        if ($dto->wasProvided('notifyOnInboundSms')) {
            $attributes[OrganizerSmsSettingDomainObjectAbstract::NOTIFY_ON_INBOUND_SMS] = $dto->notifyOnInboundSms;
        }

        if ($dto->wasProvided('assistantEnabled')) {
            $attributes[OrganizerSmsSettingDomainObjectAbstract::ASSISTANT_ENABLED] = $dto->assistantEnabled;
        }

        if ($dto->wasProvided('assistantAutoSend')) {
            $attributes[OrganizerSmsSettingDomainObjectAbstract::ASSISTANT_AUTO_SEND] = $dto->assistantAutoSend;
        }

        if ($dto->wasProvided('assistantHoldingReply')) {
            $holdingReply = $this->cleanInstructions($dto->assistantHoldingReply);
            $attributes[OrganizerSmsSettingDomainObjectAbstract::ASSISTANT_HOLDING_REPLY] = $holdingReply !== null
                ? SmsAssistantReplyGenerator::cleanText($holdingReply)
                : null;
        }

        if ($dto->wasProvided('liveChatEnabled')) {
            $attributes[OrganizerSmsSettingDomainObjectAbstract::LIVE_CHAT_ENABLED] = $dto->liveChatEnabled;
        }

        if ($dto->wasProvided('liveChatRelayLabel')) {
            $label = $this->cleanInstructions($dto->liveChatRelayLabel);
            $attributes[OrganizerSmsSettingDomainObjectAbstract::LIVE_CHAT_RELAY_LABEL] = $label !== null
                ? mb_substr(LiveChatReplyGenerator::cleanText($label), 0, UpdateOrganizerSmsSettingsRequest::MAX_RELAY_LABEL_LENGTH)
                : null;
        }

        if ($dto->wasProvided('assistantInstructions')) {
            $attributes[OrganizerSmsSettingDomainObjectAbstract::ASSISTANT_INSTRUCTIONS] = $this->cleanInstructions($dto->assistantInstructions);
        }

        if ($dto->wasProvided('organizerAssistantEnabled')) {
            $attributes[OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ASSISTANT_ENABLED] = $dto->organizerAssistantEnabled;
        }

        if ($dto->wasProvided('organizerAssistantInstructions')) {
            $attributes[OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ASSISTANT_INSTRUCTIONS] = $this->cleanInstructions($dto->organizerAssistantInstructions);
        }

        if ($dto->wasProvided('defaultRemindersEnabled')) {
            $this->settingsService->setDefaultRemindersEnabled($dto->organizerId, $dto->defaultRemindersEnabled);
        }

        if ($attributes !== []) {
            $settings = $this->settingsRepository->updateFromArray($settings->getId(), $attributes);
        }

        return new OrganizerSmsSettingsViewDTO(
            settings: $settings,
            webhookUrl: $this->settingsService->getWebhookUrl($settings),
            assistantAvailable: $this->assistantService->isAvailable(),
            sharedLine: $this->settingsService->findSharedLine($settings),
            defaultRemindersEnabled: $this->settingsService->defaultRemindersEnabled($dto->organizerId),
        );
    }

    /**
     * @throws InvalidSmsPhoneNumberException
     */
    private function normalizeFromNumber(?string $fromNumber): ?string
    {
        if ($fromNumber === null || trim($fromNumber) === '') {
            return null;
        }

        $normalized = SmsPhoneNormalizer::normalize($fromNumber);
        if ($normalized === null) {
            throw new InvalidSmsPhoneNumberException(__('Invalid phone number'));
        }

        return $normalized;
    }

    private function cleanInstructions(?string $instructions): ?string
    {
        $purified = (string) $this->purifier->purify($instructions);
        $text = trim(strip_tags(html_entity_decode($purified, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $text !== '' ? $text : null;
    }
}
