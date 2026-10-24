<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\Refund\Approval;

use HiEvents\DomainObjects\Enums\LiveChatAuthorType;
use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\LiveChatSessionDomainObjectAbstract;
use HiEvents\DomainObjects\LiveChatRefundRequestDomainObject;
use HiEvents\DomainObjects\LiveChatSessionDomainObject;
use HiEvents\Exceptions\InvalidSmsPhoneNumberException;
use HiEvents\Exceptions\SmsGatewayException;
use HiEvents\Exceptions\SmsGatewayNotConfiguredException;
use HiEvents\Exceptions\SmsRecipientOptedOutException;
use HiEvents\Mail\LiveChat\LiveChatRefundUpdateMail;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\LiveChatSessionRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use HiEvents\Services\Domain\LiveChat\LiveChatService;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\SmsSendService;
use Illuminate\Contracts\Mail\Mailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes to the customer of a refund request by the channel they left: SMS when the chat has their number, otherwise
 * the e-mail of the order. Every message is also added to the chat, for when they come back to it.
 */
class RefundCustomerNotifier
{
    public const CHANNEL_SMS = 'SMS';

    public const CHANNEL_EMAIL = 'EMAIL';

    public function __construct(
        private readonly SmsSendService $sendService,
        private readonly LiveChatService $liveChatService,
        private readonly LiveChatSessionRepositoryInterface $sessionRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly CustomerLocaleResolver $localeResolver,
        private readonly Mailer $mailer,
        private readonly LoggerInterface $logger,
    ) {}

    public function locale(LiveChatRefundRequestDomainObject $request): string
    {
        return $this->localeResolver->forOrganizerId($request->getOrganizerId());
    }

    /**
     * @return string|null The channel used, null when the customer could not be reached
     */
    public function notify(LiveChatRefundRequestDomainObject $request, string $text, bool $alsoInChat = true): ?string
    {
        if ($alsoInChat) {
            $this->recordInChat($request, $text);
        }

        $phone = $request->getCustomerPhone();
        if ($phone !== null && $phone !== '') {
            try {
                $this->sendService->send(new SendSmsDTO(
                    organizerId: $request->getOrganizerId(),
                    phone: $phone,
                    content: $text,
                    authorType: SmsMessageAuthorType::AUTOMATIC,
                    keepDraft: true,
                ));

                return self::CHANNEL_SMS;
            } catch (SmsRecipientOptedOutException|SmsGatewayNotConfiguredException|InvalidSmsPhoneNumberException|SmsGatewayException $exception) {
                $this->logger->info('Refund request: SMS to the customer not sent, e-mail used', [
                    'live_chat_refund_request_id' => $request->getId(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $email = $request->getCustomerEmail();
        if ($email === null || $email === '') {
            $this->logger->warning('Refund request: no way to reach the customer', ['live_chat_refund_request_id' => $request->getId()]);

            return null;
        }

        try {
            $this->mailer
                ->to($email)
                ->locale($this->locale($request))
                ->send(new LiveChatRefundUpdateMail(
                    event: $this->event($request),
                    organizer: $this->organizerRepository->findById($request->getOrganizerId()),
                    reference: RefundApprovalService::reference($request),
                    text: $text,
                ));
        } catch (Throwable $exception) {
            $this->logger->error('Refund request: e-mail to the customer not sent', [
                'live_chat_refund_request_id' => $request->getId(),
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        return self::CHANNEL_EMAIL;
    }

    public function recordInChat(LiveChatRefundRequestDomainObject $request, string $text): void
    {
        $sessionId = $request->getLiveChatSessionId();
        if ($sessionId === null) {
            return;
        }

        /** @var LiveChatSessionDomainObject|null $session */
        $session = $this->sessionRepository->findFirstWhere([LiveChatSessionDomainObjectAbstract::ID => $sessionId]);
        if ($session === null) {
            return;
        }

        try {
            $this->liveChatService->record($session, LiveChatAuthorType::AGENT, $text);
        } catch (Throwable $exception) {
            $this->logger->warning('Refund request: message not added to the chat', [
                'live_chat_refund_request_id' => $request->getId(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function event(LiveChatRefundRequestDomainObject $request): ?EventDomainObject
    {
        return $request->getEventId() === null
            ? null
            : $this->eventRepository->findFirstWhere([EventDomainObjectAbstract::ID => $request->getEventId()]);
    }
}
