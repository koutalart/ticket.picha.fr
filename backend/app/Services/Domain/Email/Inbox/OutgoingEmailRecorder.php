<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Email\Inbox;

use HiEvents\DomainObjects\EmailMessageDomainObject;
use HiEvents\DomainObjects\EmailThreadDomainObject;
use HiEvents\DomainObjects\Enums\EmailMessageDirection;
use HiEvents\DomainObjects\Generated\EmailMessageDomainObjectAbstract;
use HiEvents\DomainObjects\Status\EmailMessageStatus;
use HiEvents\Mail\Attendee\AttendeeDetailsChangedMail;
use HiEvents\Mail\Attendee\AttendeeTicketMail;
use HiEvents\Mail\Event\EventMessage;
use HiEvents\Mail\Inbox\OrganizerDirectEmail;
use HiEvents\Mail\LiveChat\LiveChatRefundUpdateMail;
use HiEvents\Mail\Order\OrderCancelled;
use HiEvents\Mail\Order\OrderDetailsChangedMail;
use HiEvents\Mail\Order\OrderFailed;
use HiEvents\Mail\Order\OrderRefunded;
use HiEvents\Mail\Order\OrderSummary;
use HiEvents\Mail\Order\PaymentSuccessButOrderExpiredMail;
use HiEvents\Mail\Waitlist\WaitlistConfirmationMail;
use HiEvents\Mail\Waitlist\WaitlistOfferExpiredMail;
use HiEvents\Mail\Waitlist\WaitlistOfferMail;
use HiEvents\Repository\Interfaces\EmailMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\EmailThreadRepositoryInterface;
use Illuminate\Config\Repository;
use Illuminate\Mail\SentMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;
use WeakMap;

/**
 * Logs every e-mail the platform sends to a customer on behalf of an organizer, and gives it the reply address of
 * its thread. E-mails to the organizer team, platform alerts and one-time codes are left out on purpose.
 */
class OutgoingEmailRecorder
{
    public const TRACKED_MAILABLES = [
        OrderSummary::class,
        OrderRefunded::class,
        OrderCancelled::class,
        OrderFailed::class,
        OrderDetailsChangedMail::class,
        PaymentSuccessButOrderExpiredMail::class,
        AttendeeTicketMail::class,
        AttendeeDetailsChangedMail::class,
        EventMessage::class,
        WaitlistConfirmationMail::class,
        WaitlistOfferMail::class,
        WaitlistOfferExpiredMail::class,
        LiveChatRefundUpdateMail::class,
    ];

    /** @var WeakMap<Email, array<string, mixed>>|null */
    private static ?WeakMap $pending = null;

    private static ?int $expectedComposerMessageId = null;

    public function __construct(
        private readonly OutgoingEmailContextResolver $contextResolver,
        private readonly EmailThreadService $threadService,
        private readonly EmailReplyAddressService $replyAddressService,
        private readonly EmailThreadRepositoryInterface $threadRepository,
        private readonly EmailMessageRepositoryInterface $messageRepository,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function expectComposerMessage(?int $messageId): void
    {
        self::$expectedComposerMessageId = $messageId;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function prepare(Email $message, array $data): void
    {
        try {
            $this->doPrepare($message, $data);
        } catch (Throwable $exception) {
            $this->logger->warning('Unable to prepare the e-mail log entry', ['exception' => $exception->getMessage()]);
        }
    }

    public function recordSent(SentMessage $sent): void
    {
        try {
            $this->doRecordSent($sent);
        } catch (Throwable $exception) {
            $this->logger->warning('Unable to record the sent e-mail', ['exception' => $exception->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function doPrepare(Email $message, array $data): void
    {
        $mailable = $data['__laravel_mailable'] ?? null;

        if ($mailable === OrganizerDirectEmail::class && self::$expectedComposerMessageId !== null) {
            $pending = $this->pending();
            $pending[$message] = ['composer_message_id' => self::$expectedComposerMessageId];
            self::$expectedComposerMessageId = null;
            $this->ensureMessageId($message);

            return;
        }

        if (! in_array($mailable, self::TRACKED_MAILABLES, true)) {
            return;
        }

        $recipient = $message->getTo()[0] ?? null;
        $context = $this->contextResolver->resolve($data);

        if (! $recipient instanceof Address || $context === null) {
            return;
        }

        if ($this->threadRepository->isAccountUserEmail($context->accountId, $recipient->getAddress())) {
            return;
        }

        $thread = $this->threadService->findOrCreate(
            accountId: $context->accountId,
            organizerId: $context->organizerId,
            email: $recipient->getAddress(),
            contactName: $context->contactName ?? ($recipient->getName() !== '' ? $recipient->getName() : null),
        );

        if ($this->config->get('services.email_inbox.track_transactional_replies')) {
            $replyAddress = $this->replyAddressService->addressFor($thread->getId(), $context->organizerId);

            if ($replyAddress !== null) {
                $message->replyTo(new Address($replyAddress), ...$message->getReplyTo());
            }
        }

        $pending = $this->pending();
        $pending[$message] = [
            'thread' => $thread,
            'event_id' => $context->eventId,
            'order_id' => $context->orderId,
            'attendee_id' => $context->attendeeId,
            'source' => class_basename($mailable),
        ];

        $this->ensureMessageId($message);
    }

    private function doRecordSent(SentMessage $sent): void
    {
        $original = $sent->getOriginalMessage();

        $map = $this->pending();

        if (! $original instanceof Email || ! isset($map[$original])) {
            return;
        }

        $pending = $map[$original];
        unset($map[$original]);

        $rfcMessageId = $original->getHeaders()->get('Message-ID')?->getBodyAsString();
        $providerMessageId = $sent->getMessageId();
        $providerMessageId = $providerMessageId !== '' && '<'.$providerMessageId.'>' !== $rfcMessageId ? $providerMessageId : null;

        if (isset($pending['composer_message_id'])) {
            $this->messageRepository->updateFromArray($pending['composer_message_id'], [
                EmailMessageDomainObjectAbstract::STATUS => EmailMessageStatus::SENT->name,
                EmailMessageDomainObjectAbstract::PROVIDER => $this->provider(),
                EmailMessageDomainObjectAbstract::PROVIDER_MESSAGE_ID => $providerMessageId,
                EmailMessageDomainObjectAbstract::RFC_MESSAGE_ID => $rfcMessageId,
                EmailMessageDomainObjectAbstract::STATUS_UPDATED_AT => now()->toDateTimeString(),
            ]);

            return;
        }

        /** @var EmailThreadDomainObject $thread */
        $thread = $pending['thread'];
        $from = $original->getFrom()[0] ?? null;
        $text = $original->getTextBody();
        $text = is_string($text) ? $text : (is_string($original->getHtmlBody()) ? strip_tags($original->getHtmlBody()) : null);

        /** @var EmailMessageDomainObject $message */
        $message = $this->messageRepository->create([
            EmailMessageDomainObjectAbstract::EMAIL_THREAD_ID => $thread->getId(),
            EmailMessageDomainObjectAbstract::ORGANIZER_ID => $thread->getOrganizerId(),
            EmailMessageDomainObjectAbstract::EVENT_ID => $pending['event_id'],
            EmailMessageDomainObjectAbstract::ORDER_ID => $pending['order_id'],
            EmailMessageDomainObjectAbstract::ATTENDEE_ID => $pending['attendee_id'],
            EmailMessageDomainObjectAbstract::DIRECTION => EmailMessageDirection::OUTBOUND->name,
            EmailMessageDomainObjectAbstract::SOURCE => $pending['source'],
            EmailMessageDomainObjectAbstract::FROM_EMAIL => $from?->getAddress(),
            EmailMessageDomainObjectAbstract::FROM_NAME => $from !== null && $from->getName() !== '' ? mb_substr($from->getName(), 0, 150) : null,
            EmailMessageDomainObjectAbstract::TO_EMAIL => $thread->getEmail(),
            EmailMessageDomainObjectAbstract::SUBJECT => $original->getSubject() !== null ? mb_substr($original->getSubject(), 0, 255) : null,
            EmailMessageDomainObjectAbstract::PREVIEW => EmailThreadService::preview($text),
            EmailMessageDomainObjectAbstract::STATUS => EmailMessageStatus::SENT->name,
            EmailMessageDomainObjectAbstract::PROVIDER => $this->provider(),
            EmailMessageDomainObjectAbstract::PROVIDER_MESSAGE_ID => $providerMessageId,
            EmailMessageDomainObjectAbstract::RFC_MESSAGE_ID => $rfcMessageId,
            EmailMessageDomainObjectAbstract::STATUS_UPDATED_AT => now()->toDateTimeString(),
        ]);

        $this->threadService->applyMessage($thread, $message);
    }

    private function ensureMessageId(Email $message): void
    {
        $headers = $message->getHeaders();

        if ($headers->has('Message-ID')) {
            return;
        }

        $from = $message->getFrom()[0] ?? null;
        $domain = $from !== null ? substr($from->getAddress(), strrpos($from->getAddress(), '@') + 1) : 'localhost';

        $headers->addIdHeader('Message-ID', bin2hex(random_bytes(16)).'@'.$domain);
    }

    private function provider(): string
    {
        return mb_strtoupper((string) $this->config->get('services.email_inbox.provider', 'RESEND'));
    }

    /**
     * @return WeakMap<Email, array<string, mixed>>
     */
    private function pending(): WeakMap
    {
        return self::$pending ??= new WeakMap;
    }
}
