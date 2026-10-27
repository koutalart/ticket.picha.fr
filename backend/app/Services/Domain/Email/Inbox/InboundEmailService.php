<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Email\Inbox;

use HiEvents\DomainObjects\EmailMessageDomainObject;
use HiEvents\DomainObjects\EmailThreadDomainObject;
use HiEvents\DomainObjects\Enums\EmailMessageDirection;
use HiEvents\DomainObjects\Generated\EmailMessageDomainObjectAbstract;
use HiEvents\DomainObjects\Status\EmailMessageStatus;
use HiEvents\Repository\Interfaces\EmailMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\EmailThreadRepositoryInterface;
use HiEvents\Services\Infrastructure\EmailProvider\DTO\InboundEmailDTO;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

class InboundEmailService
{
    public const SOURCE = 'REPLY';

    private const MAX_TEXT_LENGTH = 50000;

    private const MAX_HTML_LENGTH = 200000;

    public function __construct(
        private readonly EmailThreadRepositoryInterface $threadRepository,
        private readonly EmailMessageRepositoryInterface $messageRepository,
        private readonly EmailReplyAddressService $replyAddressService,
        private readonly EmailThreadService $threadService,
        private readonly HtmlPurifierService $htmlPurifier,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Files a received e-mail in the thread its reply address (or, failing that, its In-Reply-To / References
     * headers) points to. E-mails matching no thread are ignored: the inbox never guesses the organizer from the
     * sender alone, the same customer may write to several organizers.
     */
    public function handle(InboundEmailDTO $email, string $provider): ?EmailMessageDomainObject
    {
        /** @var EmailMessageDomainObject|null $existing */
        $existing = $this->messageRepository->findFirstWhere([
            EmailMessageDomainObjectAbstract::PROVIDER_MESSAGE_ID => $email->providerEmailId,
        ]);

        if ($existing !== null) {
            return $existing;
        }

        [$thread, $replyAddress] = $this->resolveThread($email);

        if ($thread === null) {
            $this->logger->info('Received e-mail matches no e-mail thread, ignored', [
                'provider_email_id' => $email->providerEmailId,
            ]);

            return null;
        }

        $html = $email->html !== null ? $this->htmlPurifier->purify(mb_substr($email->html, 0, self::MAX_HTML_LENGTH)) : null;
        $text = $email->text ?? ($html !== null ? html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>/i', "\n", $html) ?? ''), ENT_QUOTES | ENT_HTML5) : null);
        $text = $text !== null ? mb_substr($text, 0, self::MAX_TEXT_LENGTH) : null;

        try {
            /** @var EmailMessageDomainObject $message */
            $message = $this->messageRepository->create([
                EmailMessageDomainObjectAbstract::EMAIL_THREAD_ID => $thread->getId(),
                EmailMessageDomainObjectAbstract::ORGANIZER_ID => $thread->getOrganizerId(),
                EmailMessageDomainObjectAbstract::DIRECTION => EmailMessageDirection::INBOUND->name,
                EmailMessageDomainObjectAbstract::SOURCE => self::SOURCE,
                EmailMessageDomainObjectAbstract::FROM_EMAIL => mb_substr($email->fromEmail, 0, 255),
                EmailMessageDomainObjectAbstract::FROM_NAME => $email->fromName,
                EmailMessageDomainObjectAbstract::TO_EMAIL => mb_substr($replyAddress ?? ($email->recipients[0] ?? ''), 0, 255),
                EmailMessageDomainObjectAbstract::SUBJECT => $email->subject !== null ? mb_substr($email->subject, 0, 255) : null,
                EmailMessageDomainObjectAbstract::PREVIEW => EmailThreadService::preview(self::withoutQuotedReply($text)),
                EmailMessageDomainObjectAbstract::BODY_TEXT => $text,
                EmailMessageDomainObjectAbstract::BODY_HTML => $html !== '' ? $html : null,
                EmailMessageDomainObjectAbstract::ATTACHMENTS => $email->attachments !== [] ? $email->attachments : null,
                EmailMessageDomainObjectAbstract::STATUS => EmailMessageStatus::RECEIVED->name,
                EmailMessageDomainObjectAbstract::PROVIDER => $provider,
                EmailMessageDomainObjectAbstract::PROVIDER_MESSAGE_ID => $email->providerEmailId,
                EmailMessageDomainObjectAbstract::RFC_MESSAGE_ID => $email->messageId !== null ? mb_substr($email->messageId, 0, 255) : null,
                EmailMessageDomainObjectAbstract::IN_REPLY_TO => $email->references !== [] ? mb_substr($email->references[array_key_last($email->references)], 0, 255) : null,
                EmailMessageDomainObjectAbstract::STATUS_UPDATED_AT => now()->toDateTimeString(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->messageRepository->findFirstWhere([
                EmailMessageDomainObjectAbstract::PROVIDER_MESSAGE_ID => $email->providerEmailId,
            ]);
        }

        $this->threadService->applyMessage($thread, $message);

        return $message;
    }

    public static function withoutQuotedReply(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $kept = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/^\s*>/u', $line) === 1
                || preg_match('/^\s*(Le|On)\s.{0,200}(a écrit|wrote)\s*:\s*$/iu', $line) === 1
                || preg_match('/^\s*-{2,}\s*(Original Message|Message d\'origine)/iu', $line) === 1
                || preg_match('/^\s*(De|From)\s*:\s.+@/iu', $line) === 1) {
                break;
            }

            $kept[] = $line;
        }

        $result = trim(implode("\n", $kept));

        return $result !== '' ? $result : trim($text);
    }

    /**
     * @return array{0: ?EmailThreadDomainObject, 1: ?string}
     */
    private function resolveThread(InboundEmailDTO $email): array
    {
        foreach ($this->replyAddressService->extractCandidates($email->recipients) as $candidate) {
            /** @var EmailThreadDomainObject|null $thread */
            $thread = $this->threadRepository->findFirst($candidate['thread_id']);

            if ($thread !== null && $this->replyAddressService->isValid($thread->getId(), $thread->getOrganizerId(), $candidate['signature'])) {
                return [$thread, $this->replyAddressService->addressFor($thread->getId(), $thread->getOrganizerId())];
            }
        }

        foreach (array_reverse($email->references) as $reference) {
            /** @var EmailMessageDomainObject|null $original */
            $original = $this->messageRepository->findFirstWhere([
                EmailMessageDomainObjectAbstract::RFC_MESSAGE_ID => $reference,
                EmailMessageDomainObjectAbstract::DIRECTION => EmailMessageDirection::OUTBOUND->name,
            ]);

            if ($original !== null) {
                return [$this->threadRepository->findFirst($original->getEmailThreadId()), null];
            }
        }

        return [null, null];
    }
}
