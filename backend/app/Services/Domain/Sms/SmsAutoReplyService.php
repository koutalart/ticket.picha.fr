<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Sms;

use HiEvents\DomainObjects\Enums\CustomerAddressForm;
use HiEvents\DomainObjects\Enums\SmsAssistantOutcome;
use HiEvents\DomainObjects\Enums\SmsAutoReplySkipReason;
use HiEvents\DomainObjects\Enums\SmsAutoReplyType;
use HiEvents\DomainObjects\Enums\SmsDraftSource;
use HiEvents\DomainObjects\Enums\SmsMessageDirection;
use HiEvents\DomainObjects\Generated\OrganizerSmsSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsConversationDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsMessageDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsConversationStatus;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Helper\SmsAcknowledgementDetector;
use HiEvents\Helper\SmsSensitiveTopicDetector;
use HiEvents\Jobs\Sms\SendSmsAutoReplyJob;
use HiEvents\Repository\Interfaces\OrganizerSmsSettingRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use HiEvents\Services\Domain\Sms\DTO\SmsAssistantResultDTO;
use HiEvents\Services\Domain\Sms\DTO\SmsAutoReplyCheckDTO;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Answers a customer SMS on its own when the organizer allows it. The assistant's draft leaves only for a simple
 * question answered from the organizer's instructions and data. Otherwise (sensitive case, doubt, conversation waiting
 * for the team) only a holding reply leaves, which promises nothing, and the conversation stays waiting for the team.
 * At most one automatic reply per customer SMS, and none once somebody wrote to the contact after it.
 */
class SmsAutoReplyService
{
    public const DELAY_SECONDS = 5;

    private const PENDING_WINDOW_HOURS = 24;

    private const HOLDING_COOLDOWN_HOURS = 6;

    private const RECENT_INBOUND_TO_CHECK = 3;

    private const THREAD_LENGTH = 20;

    public function __construct(
        private readonly SmsAssistantService $assistantService,
        private readonly OrganizerSmsSettingRepositoryInterface $settingsRepository,
        private readonly SmsConversationRepositoryInterface $conversationRepository,
        private readonly SmsMessageRepositoryInterface $messageRepository,
        private readonly SmsConversationService $conversationService,
        private readonly SmsLineService $lineService,
        private readonly CustomerLocaleResolver $customerLocaleResolver,
    ) {}

    public function isEnabledFor(?OrganizerSmsSettingDomainObject $settings): bool
    {
        return $settings !== null
            && $settings->getAssistantAutoSend()
            && $this->assistantService->isActiveFor($settings);
    }

    /**
     * The holding reply of the organizer: the one the assistant wrote in its tone, the organizer's own text, or a default.
     */
    public function holdingReplyFor(OrganizerSmsSettingDomainObject $settings, ?SmsAssistantResultDTO $result): string
    {
        foreach ([$result?->holdingReply, $settings->getAssistantHoldingReply()] as $candidate) {
            $text = SmsAssistantReplyGenerator::cleanText((string) $candidate);
            if ($text !== '') {
                return $text;
            }
        }

        $tutoiement = $this->customerLocaleResolver->addressFormForOrganizerId($settings->getOrganizerId()) === CustomerAddressForm::TU;

        return __(
            'Good question, I am checking with the team and will get back to you very soon.',
            [],
            $tutoiement ? CustomerLocale::FRENCH_TUTOIEMENT : 'fr',
        );
    }

    /**
     * Queues, a few seconds later, the automatic reply to a customer SMS the assistant just processed.
     */
    public function queueIfEligible(int $conversationId, int $messageId, ?SmsAssistantResultDTO $result): bool
    {
        if ($result === null || $result->outcome === SmsAssistantOutcome::FAILED) {
            return false;
        }

        $conversation = $this->findConversation($conversationId);
        if ($conversation === null) {
            return false;
        }

        $settings = $this->findSettings($conversation->getOrganizerId());
        if (! $this->isEnabledFor($settings)) {
            return false;
        }

        $answer = $result->outcome === SmsAssistantOutcome::DRAFT && $result->autoSendSafe && trim((string) $result->reply) !== ''
            ? (string) $result->reply
            : null;

        SendSmsAutoReplyJob::dispatch($conversationId, $messageId, $answer, $this->holdingReplyFor($settings, $result))
            ->delay(now()->addSeconds(self::DELAY_SECONDS));

        return true;
    }

    /**
     * Decides, right before sending, what may leave without the team: the answer, the holding reply, or nothing.
     */
    public function check(int $conversationId, int $messageId, ?string $answer, ?string $holdingReply): SmsAutoReplyCheckDTO
    {
        $answer = trim((string) $answer) !== '' ? (string) $answer : null;
        $holdingReply = trim((string) $holdingReply) !== '' ? (string) $holdingReply : null;

        if ($answer === null && $holdingReply === null) {
            return new SmsAutoReplyCheckDTO(SmsAutoReplySkipReason::EMPTY_REPLY);
        }

        $conversation = $this->findConversation($conversationId);
        if ($conversation === null) {
            return new SmsAutoReplyCheckDTO(SmsAutoReplySkipReason::CONVERSATION_NOT_FOUND);
        }

        $settings = $this->findSettings($conversation->getOrganizerId());
        if (! $this->isEnabledFor($settings)) {
            return new SmsAutoReplyCheckDTO(SmsAutoReplySkipReason::DISABLED, $conversation);
        }

        /** @var SmsMessageDomainObject|null $message */
        $message = $this->messageRepository->findFirstWhere([
            SmsMessageDomainObjectAbstract::ID => $messageId,
            SmsMessageDomainObjectAbstract::SMS_CONVERSATION_ID => $conversation->getId(),
        ]);
        if ($message === null || ! SmsAssistantService::needsReply($message)) {
            return new SmsAutoReplyCheckDTO(SmsAutoReplySkipReason::NOT_REPLYABLE, $conversation);
        }

        $thread = $this->messageRepository->findLatestForConversation($conversation->getId(), self::THREAD_LENGTH);
        if ($thread->last()?->getId() !== $messageId) {
            return new SmsAutoReplyCheckDTO(SmsAutoReplySkipReason::NOT_LATEST, $conversation);
        }

        $blocked = $this->findBlockingReason($conversation, $settings, $message);
        if ($blocked !== null) {
            return new SmsAutoReplyCheckDTO($blocked, $conversation);
        }

        if ($answer !== null) {
            $answerSkip = $this->findAnswerSkipReason($conversation, $message, $answer);
            if ($answerSkip === null) {
                return new SmsAutoReplyCheckDTO(null, $conversation, SmsAutoReplyType::ANSWER, $answer);
            }

            if ($answerSkip === SmsAutoReplySkipReason::DRAFT_CHANGED) {
                return new SmsAutoReplyCheckDTO($answerSkip, $conversation);
            }
        }

        if ($holdingReply === null) {
            return new SmsAutoReplyCheckDTO(SmsAutoReplySkipReason::EMPTY_REPLY, $conversation);
        }

        if ($this->holdingReplyJustSent($thread)) {
            return new SmsAutoReplyCheckDTO(SmsAutoReplySkipReason::HOLDING_ALREADY_SENT, $conversation);
        }

        return new SmsAutoReplyCheckDTO(null, $conversation, SmsAutoReplyType::HOLDING, $holdingReply);
    }

    /**
     * A holding reply leaves the conversation waiting for the team: it must be in « À traiter ».
     */
    public function keepWaitingForTeam(SmsConversationDomainObject $conversation): void
    {
        if ($conversation->getStatus() === SmsConversationStatus::NEEDS_HUMAN->name) {
            return;
        }

        $this->conversationRepository->updateWhere([
            SmsConversationDomainObjectAbstract::STATUS => SmsConversationStatus::NEEDS_HUMAN->name,
            SmsConversationDomainObjectAbstract::NEEDS_HUMAN_REASON => __('A holding reply was sent automatically, the team must answer'),
        ], [
            SmsConversationDomainObjectAbstract::ID => $conversation->getId(),
        ]);
    }

    /**
     * Reasons that forbid any automatic reply, even a holding one.
     */
    private function findBlockingReason(
        SmsConversationDomainObject $conversation,
        OrganizerSmsSettingDomainObject $settings,
        SmsMessageDomainObject $message,
    ): ?SmsAutoReplySkipReason {
        if (SmsAcknowledgementDetector::isAcknowledgement($message->getContent())) {
            return SmsAutoReplySkipReason::ACKNOWLEDGEMENT;
        }

        if ($this->conversationService->isOptedOut($conversation->getOrganizerId(), $conversation->getPhone())) {
            return SmsAutoReplySkipReason::OPTED_OUT;
        }

        if ($this->messageRepository->countWhere([
            SmsMessageDomainObjectAbstract::AUTO_REPLY_TO_MESSAGE_ID => $message->getId(),
        ]) > 0) {
            return SmsAutoReplySkipReason::ALREADY_ANSWERED;
        }

        try {
            $line = $this->lineService->resolveLine($settings);
        } catch (SmsGatewayNotConfiguredException) {
            return SmsAutoReplySkipReason::LINE_UNAVAILABLE;
        }

        $organizerIds = $this->lineService->members($line)
            ->map(fn (OrganizerSmsSettingDomainObject $member) => $member->getOrganizerId())
            ->push($conversation->getOrganizerId())
            ->unique()
            ->values()
            ->all();

        if ($this->messageRepository->hasOutboundToPhoneAfter(
            $organizerIds,
            $conversation->getPhone(),
            $message->getId(),
            Carbon::now('UTC')->subHours(self::PENDING_WINDOW_HOURS)->toDateTimeString(),
        )) {
            return SmsAutoReplySkipReason::OUTBOUND_AFTER_MESSAGE;
        }

        return null;
    }

    /**
     * Reasons the answer itself stays a draft for the team (a holding reply may still leave).
     */
    private function findAnswerSkipReason(SmsConversationDomainObject $conversation, SmsMessageDomainObject $message, string $answer): ?SmsAutoReplySkipReason
    {
        if (! $this->isCurrentAgentDraft($conversation, $answer)) {
            return SmsAutoReplySkipReason::DRAFT_CHANGED;
        }

        if ($conversation->getStatus() === SmsConversationStatus::NEEDS_HUMAN->name) {
            return SmsAutoReplySkipReason::NEEDS_HUMAN;
        }

        if ($this->involvesSensitiveTopic($conversation, $message, $answer)) {
            return SmsAutoReplySkipReason::SENSITIVE;
        }

        return null;
    }

    /**
     * @param  Collection<int, SmsMessageDomainObject>  $thread
     */
    private function holdingReplyJustSent(Collection $thread): bool
    {
        $lastOutbound = $thread
            ->filter(fn (SmsMessageDomainObject $message) => $message->getDirection() === SmsMessageDirection::OUTBOUND->name)
            ->last();

        if ($lastOutbound === null || $lastOutbound->getAutoReplyType() !== SmsAutoReplyType::HOLDING->name) {
            return false;
        }

        $sentAt = $lastOutbound->getCreatedAt();

        return $sentAt === null
            || Carbon::parse($sentAt, 'UTC')->greaterThan(Carbon::now('UTC')->subHours(self::HOLDING_COOLDOWN_HOURS));
    }

    private function isCurrentAgentDraft(SmsConversationDomainObject $conversation, string $answer): bool
    {
        return $conversation->getDraftSource() === SmsDraftSource::AGENT->name
            && $conversation->getDraftReply() !== null
            && self::normalizeReply($conversation->getDraftReply()) === self::normalizeReply($answer);
    }

    private function involvesSensitiveTopic(SmsConversationDomainObject $conversation, SmsMessageDomainObject $message, string $answer): bool
    {
        if (SmsSensitiveTopicDetector::isSensitive($message->getContent()) || SmsSensitiveTopicDetector::isSensitive($answer)) {
            return true;
        }

        return $this->messageRepository->findLatestInbound($conversation->getId(), self::RECENT_INBOUND_TO_CHECK)
            ->contains(fn (SmsMessageDomainObject $inbound) => SmsSensitiveTopicDetector::isSensitive($inbound->getContent()));
    }

    private static function normalizeReply(string $reply): string
    {
        return trim(str_replace("\r\n", "\n", $reply));
    }

    private function findConversation(int $conversationId): ?SmsConversationDomainObject
    {
        return $this->conversationRepository->findFirstWhere([
            SmsConversationDomainObjectAbstract::ID => $conversationId,
        ]);
    }

    private function findSettings(int $organizerId): ?OrganizerSmsSettingDomainObject
    {
        return $this->settingsRepository->findFirstWhere([
            OrganizerSmsSettingDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);
    }
}
