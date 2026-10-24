<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat;

use HiEvents\DomainObjects\Enums\LiveChatAuthorType;
use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\Enums\SmsMessageChannel;
use HiEvents\DomainObjects\Enums\SmsMessageDirection;
use HiEvents\DomainObjects\Generated\SmsConversationDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsMessageDomainObjectAbstract;
use HiEvents\DomainObjects\LiveChatMessageDomainObject;
use HiEvents\DomainObjects\LiveChatSessionDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\OrganizerSmsSettingDomainObject;
use HiEvents\DomainObjects\SmsConversationDomainObject;
use HiEvents\DomainObjects\SmsMessageDomainObject;
use HiEvents\DomainObjects\Status\SmsConversationStatus;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Mail\LiveChat\LiveChatContactAlertMail;
use HiEvents\Repository\Interfaces\SmsConversationRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessageRepositoryInterface;
use HiEvents\Services\Domain\Sms\SmsAlertService;
use HiEvents\Services\Domain\Sms\SmsConversationService;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Collection;

/**
 * Hands a web chat over to the organizer once the visitor left a phone number (an SMS conversation « À traiter »,
 * answered by SMS on the organizer line) or only an e-mail (an alert e-mail with the transcript, answered from the mailbox).
 */
class LiveChatHandoffService
{
    private const REASON_PREFIX = 'Chat web : ';

    private const MAX_REASON_LENGTH = 255;

    public function __construct(
        private readonly SmsConversationService $conversationService,
        private readonly SmsConversationRepositoryInterface $conversationRepository,
        private readonly SmsMessageRepositoryInterface $messageRepository,
        private readonly SmsAlertService $alertService,
        private readonly Mailer $mailer,
    ) {}

    /**
     * @param  Collection<int, LiveChatMessageDomainObject>  $transcript
     */
    public function handOffToSms(
        OrganizerSmsSettingDomainObject $settings,
        LiveChatSessionDomainObject $session,
        string $phone,
        Collection $transcript,
    ): SmsConversationDomainObject {
        $conversation = $this->conversationService->findOrCreate($settings, $phone);

        $lastInbound = null;
        $lastMessage = null;
        foreach ($transcript as $message) {
            $lastMessage = $this->copyToConversation($conversation, $message);
            if ($lastMessage->getDirection() === SmsMessageDirection::INBOUND->name) {
                $lastInbound = $lastMessage;
            }
        }

        $visitorCount = $transcript
            ->filter(fn (LiveChatMessageDomainObject $message) => $message->getAuthorType() === LiveChatAuthorType::VISITOR->name)
            ->count();

        if ($lastMessage !== null) {
            $conversation = $this->conversationService->recordActivity(
                $conversation,
                SmsMessageDirection::fromName($lastMessage->getDirection()),
                $lastMessage->getContent(),
                markUnread: false,
            );
        }

        $conversation = $this->markNeedsHuman($conversation, $session, $visitorCount);

        if ($lastInbound !== null) {
            $this->alertService->notifyInbound($settings, $lastInbound);
        }

        return $conversation;
    }

    /**
     * A chat already handed over keeps feeding its SMS conversation.
     */
    public function appendToConversation(
        OrganizerSmsSettingDomainObject $settings,
        LiveChatSessionDomainObject $session,
        LiveChatMessageDomainObject $message,
        bool $needsFollowUp,
    ): void {
        /** @var SmsConversationDomainObject|null $conversation */
        $conversation = $this->conversationRepository->findFirstWhere([
            SmsConversationDomainObjectAbstract::ID => $session->getSmsConversationId(),
        ]);
        if ($conversation === null) {
            return;
        }

        $copy = $this->copyToConversation($conversation, $message);
        $isVisitor = $message->getAuthorType() === LiveChatAuthorType::VISITOR->name;
        $conversation = $this->conversationService->recordActivity(
            $conversation,
            SmsMessageDirection::fromName($copy->getDirection()),
            $copy->getContent(),
            markUnread: $isVisitor,
        );

        if ($needsFollowUp) {
            $this->markNeedsHuman($conversation, $session, 0);
        }

        if ($isVisitor) {
            $this->alertService->notifyInbound($settings, $copy);
        }
    }

    /**
     * @param  Collection<int, LiveChatMessageDomainObject>  $transcript
     */
    public function handOffByEmail(
        OrganizerDomainObject $organizer,
        OrganizerSmsSettingDomainObject $settings,
        LiveChatSessionDomainObject $session,
        Collection $transcript,
    ): void {
        if (! $settings->getNotifyOnInboundSms() || trim((string) $organizer->getEmail()) === '') {
            return;
        }

        $this->mailer
            ->to($organizer->getEmail(), $organizer->getName())
            ->locale(CustomerLocale::get())
            ->send(new LiveChatContactAlertMail($organizer, $session, $transcript));
    }

    private function copyToConversation(
        SmsConversationDomainObject $conversation,
        LiveChatMessageDomainObject $message,
    ): SmsMessageDomainObject {
        $isVisitor = $message->getAuthorType() === LiveChatAuthorType::VISITOR->name;

        return $this->messageRepository->create([
            SmsMessageDomainObjectAbstract::SMS_CONVERSATION_ID => $conversation->getId(),
            SmsMessageDomainObjectAbstract::ORGANIZER_ID => $conversation->getOrganizerId(),
            SmsMessageDomainObjectAbstract::DIRECTION => $isVisitor ? SmsMessageDirection::INBOUND->name : SmsMessageDirection::OUTBOUND->name,
            SmsMessageDomainObjectAbstract::AUTHOR_TYPE => $isVisitor ? SmsMessageAuthorType::CONTACT->name : SmsMessageAuthorType::AGENT->name,
            SmsMessageDomainObjectAbstract::CONTENT => $message->getContent(),
            SmsMessageDomainObjectAbstract::STATUS => $isVisitor ? SmsMessageStatus::RECEIVED->name : SmsMessageStatus::DELIVERED->name,
            SmsMessageDomainObjectAbstract::PROVIDER_TIMESTAMP => $message->getCreatedAt(),
            SmsMessageDomainObjectAbstract::CHANNEL => SmsMessageChannel::WEB_CHAT->name,
        ]);
    }

    private function markNeedsHuman(
        SmsConversationDomainObject $conversation,
        LiveChatSessionDomainObject $session,
        int $newUnread,
    ): SmsConversationDomainObject {
        $reason = trim((string) $session->getFollowUpReason());

        return $this->conversationRepository->updateFromArray($conversation->getId(), [
            SmsConversationDomainObjectAbstract::STATUS => SmsConversationStatus::NEEDS_HUMAN->name,
            SmsConversationDomainObjectAbstract::NEEDS_HUMAN_REASON => mb_substr(
                self::REASON_PREFIX.($reason !== '' ? $reason : __('the visitor left a phone number to be contacted')),
                0,
                self::MAX_REASON_LENGTH,
            ),
            SmsConversationDomainObjectAbstract::UNREAD_COUNT => $conversation->getUnreadCount() + $newUnread,
        ]);
    }
}
