<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Email\Inbox;

use HiEvents\DomainObjects\EmailMessageDomainObject;
use HiEvents\DomainObjects\EmailThreadDomainObject;
use HiEvents\DomainObjects\Enums\EmailMessageDirection;
use HiEvents\DomainObjects\Generated\EmailMessageDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\EmailThreadDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\EmailMessageStatus;
use HiEvents\Exceptions\EmailRecipientNotAllowedException;
use HiEvents\Exceptions\EmailSendFailedException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Mail\Inbox\OrganizerDirectEmail;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Interfaces\EmailMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\EmailThreadRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use HiEvents\Services\Domain\Email\Inbox\DTO\ComposeEmailDTO;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Illuminate\Config\Repository;
use Illuminate\Mail\Mailer;
use Psr\Log\LoggerInterface;
use Throwable;

class EmailComposerService
{
    private const MAX_REFERENCES = 10;

    public function __construct(
        private readonly EmailThreadRepositoryInterface $threadRepository,
        private readonly EmailMessageRepositoryInterface $messageRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly EmailThreadService $threadService,
        private readonly EmailReplyAddressService $replyAddressService,
        private readonly OutgoingEmailRecorder $recorder,
        private readonly HtmlPurifierService $htmlPurifier,
        private readonly CustomerLocaleResolver $customerLocaleResolver,
        private readonly Mailer $mailer,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws EmailRecipientNotAllowedException
     * @throws EmailSendFailedException
     */
    public function send(ComposeEmailDTO $compose): EmailMessageDomainObject
    {
        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $this->organizerRepository->findFirst($compose->organizerId);

        if ($organizer === null) {
            throw new ResourceNotFoundException(__('Organizer not found'));
        }

        $thread = $compose->threadId !== null
            ? $this->existingThread($compose->organizerId, $compose->threadId)
            : $this->threadForNewMessage($organizer, (string) $compose->email);

        $references = $this->references($thread->getId());
        $subject = trim((string) $compose->subject);
        if ($subject === '') {
            $subject = $this->replySubject($thread) ?? __('Message from :organizer', ['organizer' => $organizer->getName()]);
        }

        $body = trim($compose->body);
        $bodyHtml = (string) $this->htmlPurifier->purify(nl2br(e($body), false));
        $sender = $organizer->getName();

        /** @var EmailMessageDomainObject $message */
        $message = $this->messageRepository->create([
            EmailMessageDomainObjectAbstract::EMAIL_THREAD_ID => $thread->getId(),
            EmailMessageDomainObjectAbstract::ORGANIZER_ID => $organizer->getId(),
            EmailMessageDomainObjectAbstract::DIRECTION => EmailMessageDirection::OUTBOUND->name,
            EmailMessageDomainObjectAbstract::SOURCE => class_basename(OrganizerDirectEmail::class),
            EmailMessageDomainObjectAbstract::SENT_BY_USER_ID => $compose->userId,
            EmailMessageDomainObjectAbstract::FROM_EMAIL => $this->config->get('mail.from.address'),
            EmailMessageDomainObjectAbstract::FROM_NAME => mb_substr($sender, 0, 150),
            EmailMessageDomainObjectAbstract::TO_EMAIL => $thread->getEmail(),
            EmailMessageDomainObjectAbstract::SUBJECT => mb_substr($subject, 0, 255),
            EmailMessageDomainObjectAbstract::PREVIEW => EmailThreadService::preview($body),
            EmailMessageDomainObjectAbstract::BODY_TEXT => $body,
            EmailMessageDomainObjectAbstract::BODY_HTML => $bodyHtml,
            EmailMessageDomainObjectAbstract::STATUS => EmailMessageStatus::SENDING->name,
            EmailMessageDomainObjectAbstract::IN_REPLY_TO => $references !== [] ? $references[array_key_last($references)] : null,
        ]);

        $replyTo = $this->replyAddressService->addressFor($thread->getId(), $organizer->getId()) ?? $organizer->getEmail();

        $this->recorder->expectComposerMessage($message->getId());

        try {
            $this->mailer
                ->to($thread->getEmail(), $thread->getContactName())
                ->locale($this->customerLocaleResolver->forOrganizerId($organizer->getId()))
                ->sendNow(new OrganizerDirectEmail(
                    organizer: $organizer,
                    emailSubject: $subject,
                    bodyHtml: $bodyHtml,
                    replyToAddress: $replyTo,
                    references: $references,
                ));
        } catch (Throwable $exception) {
            $this->logger->error('Unable to send an organizer e-mail', [
                'organizer_id' => $organizer->getId(),
                'email_thread_id' => $thread->getId(),
                'exception' => $exception->getMessage(),
            ]);

            $failed = $this->messageRepository->updateFromArray($message->getId(), [
                EmailMessageDomainObjectAbstract::STATUS => EmailMessageStatus::FAILED->name,
                EmailMessageDomainObjectAbstract::ERROR_MESSAGE => mb_substr($exception->getMessage(), 0, 500),
                EmailMessageDomainObjectAbstract::STATUS_UPDATED_AT => now()->toDateTimeString(),
            ]);
            $this->threadService->applyMessage($thread, $failed);

            throw new EmailSendFailedException(__('The e-mail could not be sent. Please try again in a few minutes.'), 0, $exception);
        } finally {
            $this->recorder->expectComposerMessage(null);
        }

        /** @var EmailMessageDomainObject $message */
        $message = $this->messageRepository->findById($message->getId());

        if ($message->getStatus() === EmailMessageStatus::SENDING->name) {
            $message = $this->messageRepository->updateFromArray($message->getId(), [
                EmailMessageDomainObjectAbstract::STATUS => EmailMessageStatus::SENT->name,
                EmailMessageDomainObjectAbstract::STATUS_UPDATED_AT => now()->toDateTimeString(),
            ]);
        }

        $this->threadService->applyMessage($thread, $message, answersCustomer: true);

        return $message;
    }

    /**
     * @throws ResourceNotFoundException
     */
    private function existingThread(int $organizerId, int $threadId): EmailThreadDomainObject
    {
        /** @var EmailThreadDomainObject|null $thread */
        $thread = $this->threadRepository->findFirstWhere([
            EmailThreadDomainObjectAbstract::ID => $threadId,
            EmailThreadDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        if ($thread === null) {
            throw new ResourceNotFoundException(__('Conversation not found'));
        }

        return $thread;
    }

    /**
     * @throws EmailRecipientNotAllowedException
     */
    private function threadForNewMessage(OrganizerDomainObject $organizer, string $email): EmailThreadDomainObject
    {
        $email = mb_strtolower(trim($email));

        /** @var EmailThreadDomainObject|null $existing */
        $existing = $this->threadRepository->findFirstWhere([
            EmailThreadDomainObjectAbstract::ORGANIZER_ID => $organizer->getId(),
            EmailThreadDomainObjectAbstract::EMAIL => $email,
        ]);

        if ($existing === null && ! $this->threadRepository->isKnownRecipient($organizer->getId(), $email)) {
            throw new EmailRecipientNotAllowedException(__('You can only write to your own contacts, buyers and attendees.'));
        }

        if ($this->threadRepository->isUnsubscribedRecipient($organizer->getId(), $email)) {
            throw new EmailRecipientNotAllowedException(__('This contact unsubscribed from your e-mails.'));
        }

        return $existing ?? $this->threadService->findOrCreate($organizer->getAccountId(), $organizer->getId(), $email);
    }

    /**
     * @return array<int, string>
     */
    private function references(int $threadId): array
    {
        return $this->messageRepository->findWhere(
            where: [
                EmailMessageDomainObjectAbstract::EMAIL_THREAD_ID => $threadId,
                [EmailMessageDomainObjectAbstract::RFC_MESSAGE_ID, 'not null', null],
            ],
            orderAndDirections: [new OrderAndDirection(EmailMessageDomainObjectAbstract::ID, 'desc')],
        )
            ->take(self::MAX_REFERENCES)
            ->map(fn (EmailMessageDomainObject $message) => (string) $message->getRfcMessageId())
            ->reverse()
            ->values()
            ->all();
    }

    private function replySubject(EmailThreadDomainObject $thread): ?string
    {
        $subject = $thread->getLastMessageSubject();

        if ($subject === null || trim($subject) === '') {
            return null;
        }

        return preg_match('/^\s*(re|tr|fwd?)\s*:/i', $subject) === 1 ? $subject : 'Re: '.$subject;
    }
}
