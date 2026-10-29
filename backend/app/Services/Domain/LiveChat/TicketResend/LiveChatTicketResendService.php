<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\LiveChat\TicketResend;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\SmsMessageAuthorType;
use HiEvents\DomainObjects\Generated\LiveChatTicketResendRequestDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\LiveChatSessionDomainObject;
use HiEvents\DomainObjects\LiveChatTicketResendRequestDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\LiveChatTicketResendStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Helper\KioskSentinelEmail;
use HiEvents\Helper\SmsPhoneNormalizer;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Interfaces\LiveChatTicketResendRequestRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsCustomerDirectoryRepositoryInterface;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use HiEvents\Services\Domain\LiveChat\TicketResend\DTO\LiveChatTicketResendCandidateDTO;
use HiEvents\Services\Domain\LiveChat\TicketResend\DTO\LiveChatTicketResendReplyDTO;
use HiEvents\Services\Domain\LiveChat\Verification\LiveChatVerificationCodeService;
use HiEvents\Services\Domain\SelfService\SelfServiceResendEmailService;
use HiEvents\Services\Domain\Sms\DTO\SendSmsDTO;
use HiEvents\Services\Domain\Sms\SmsSendService;
use Illuminate\Config\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends the tickets again from the web chat. The visitor gives the first name and the phone number of the order;
 * a 6-digit code goes by SMS to that number (on the organizer line) and, once typed back in the chat, the order
 * confirmation is sent again to the e-mail of the order. The reply never tells whether an order was found.
 */
class LiveChatTicketResendService
{
    public const VALIDITY_MINUTES = 20;

    public const MAX_ATTEMPTS = 3;

    public const MAX_SENDS_PER_PHONE_PER_SESSION = 3;

    public const MAX_REQUESTS_PER_SESSION = 5;

    public const MAX_SENDS_PER_PHONE_PER_DAY = 5;

    public const MAX_ORDERS = 5;

    private const CANDIDATE_LIMIT = 50;

    private const AUDIT_USER_AGENT = 'PICHA live chat';

    public function __construct(
        private readonly SmsCustomerDirectoryRepositoryInterface $directoryRepository,
        private readonly LiveChatTicketResendRequestRepositoryInterface $requestRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly LiveChatVerificationCodeService $codes,
        private readonly SmsSendService $smsSendService,
        private readonly SelfServiceResendEmailService $resendEmailService,
        private readonly CustomerLocaleResolver $localeResolver,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  list<string>  $visitorTexts  what the visitor wrote in the chat: the first name and the number must come from it
     */
    public function requestCode(
        LiveChatSessionDomainObject $session,
        string $firstName,
        string $phone,
        array $visitorTexts,
        string $ip,
    ): LiveChatTicketResendReplyDTO {
        $locale = $this->locale($session);
        $normalizedPhone = self::normalizePhone($phone);
        $firstName = trim($firstName);

        if ($normalizedPhone === null) {
            return new LiveChatTicketResendReplyDTO([__('This phone number does not look valid. Can you write it again?', [], $locale)]);
        }

        if (! self::writtenByVisitor($firstName, $normalizedPhone, $visitorTexts)) {
            return new LiveChatTicketResendReplyDTO([__('To send your tickets again, I need your first name and the phone number used for the order.', [], $locale)]);
        }

        $phoneHash = $this->phoneHash($normalizedPhone);
        if ($this->limitReached($session, $phoneHash)) {
            return new LiveChatTicketResendReplyDTO(
                messages: [__('Several codes have already been requested. I am passing your request on to the organizer, who will look into it.', [], $locale)],
                needsFollowUp: true,
                reason: __('ticket resend: too many codes requested'),
            );
        }

        $this->requestRepository->updateWhere(
            [LiveChatTicketResendRequestDomainObjectAbstract::STATUS => LiveChatTicketResendStatus::EXPIRED->name],
            [
                LiveChatTicketResendRequestDomainObjectAbstract::LIVE_CHAT_SESSION_ID => $session->getId(),
                LiveChatTicketResendRequestDomainObjectAbstract::STATUS => LiveChatTicketResendStatus::CODE_SENT->name,
            ],
        );

        $orders = $this->matchingOrders($session, $firstName, $normalizedPhone);
        $code = $this->codes->generate();

        /** @var LiveChatTicketResendRequestDomainObject $request */
        $request = $this->requestRepository->create([
            LiveChatTicketResendRequestDomainObjectAbstract::LIVE_CHAT_SESSION_ID => $session->getId(),
            LiveChatTicketResendRequestDomainObjectAbstract::ORGANIZER_ID => $session->getOrganizerId(),
            LiveChatTicketResendRequestDomainObjectAbstract::PHONE_HASH => $phoneHash,
            LiveChatTicketResendRequestDomainObjectAbstract::ORDER_IDS => $orders->map(fn (LiveChatTicketResendCandidateDTO $order) => $order->orderId)->values()->all(),
            LiveChatTicketResendRequestDomainObjectAbstract::STATUS => LiveChatTicketResendStatus::CODE_SENT->name,
            LiveChatTicketResendRequestDomainObjectAbstract::CODE_HASH => $this->codes->hash($code),
            LiveChatTicketResendRequestDomainObjectAbstract::CODE_EXPIRES_AT => $this->codes->expiresAt(self::VALIDITY_MINUTES)->toDateTimeString(),
            LiveChatTicketResendRequestDomainObjectAbstract::IP_HASH => $this->hmac($ip),
        ]);

        if ($orders->isNotEmpty()) {
            $this->sendCode($session, $request, $normalizedPhone, $code, $locale);
        }

        $this->logger->info('Live chat: ticket resend code requested', [
            'live_chat_session_id' => $session->getId(),
            'ticket_resend_request_id' => $request->getId(),
            'matching_orders' => $orders->count(),
        ]);

        return new LiveChatTicketResendReplyDTO([__('If this information matches an order, you will receive a code by SMS. Write it to me here.', [], $locale)]);
    }

    public function hasPendingCode(LiveChatSessionDomainObject $session): bool
    {
        return $this->pendingRequest($session) !== null;
    }

    public function verifyCode(LiveChatSessionDomainObject $session, string $code, string $ip): LiveChatTicketResendReplyDTO
    {
        $locale = $this->locale($session);
        $request = $this->pendingRequest($session);

        if ($request === null || $this->codes->isExpired($request->getCodeExpiresAt())) {
            if ($request !== null) {
                $this->setStatus($request, LiveChatTicketResendStatus::EXPIRED);
            }

            return new LiveChatTicketResendReplyDTO([__('This code has expired. Give me your first name and phone number again to receive a new one.', [], $locale)]);
        }

        $orderIds = array_values(array_map('intval', (array) $request->getOrderIds()));
        if ($orderIds === [] || ! $this->codes->matches($request->getCodeHash(), $code)) {
            $attempts = $request->getAttempts() + 1;
            $blocked = $attempts >= self::MAX_ATTEMPTS;
            $this->requestRepository->updateFromArray($request->getId(), [
                LiveChatTicketResendRequestDomainObjectAbstract::ATTEMPTS => $attempts,
                ...($blocked ? [
                    LiveChatTicketResendRequestDomainObjectAbstract::STATUS => LiveChatTicketResendStatus::BLOCKED->name,
                    LiveChatTicketResendRequestDomainObjectAbstract::CODE_HASH => null,
                ] : []),
            ]);

            return new LiveChatTicketResendReplyDTO([$blocked
                ? __('Too many incorrect codes. Give me your first name and phone number again to receive a new code.', [], $locale)
                : __('This code is not correct. Attempts left: :count.', [
                    'count' => LiveChatVerificationCodeService::attemptsLeft(self::MAX_ATTEMPTS, $attempts),
                ], $locale)]);
        }

        $locked = $this->requestRepository->updateWhere(
            [
                LiveChatTicketResendRequestDomainObjectAbstract::STATUS => LiveChatTicketResendStatus::RESENT->name,
                LiveChatTicketResendRequestDomainObjectAbstract::VERIFIED_AT => Carbon::now('UTC')->toDateTimeString(),
                LiveChatTicketResendRequestDomainObjectAbstract::CODE_HASH => null,
            ],
            [
                LiveChatTicketResendRequestDomainObjectAbstract::ID => $request->getId(),
                LiveChatTicketResendRequestDomainObjectAbstract::STATUS => LiveChatTicketResendStatus::CODE_SENT->name,
            ],
        );
        if ($locked !== 1) {
            return new LiveChatTicketResendReplyDTO([__('This code has expired. Give me your first name and phone number again to receive a new one.', [], $locale)]);
        }

        $emails = [];
        try {
            foreach ($this->orderRepository->findWhereIn(OrderDomainObjectAbstract::ID, $orderIds) as $order) {
                /** @var OrderDomainObject $order */
                if (! self::isResendable($order->getStatus(), $order->getPaymentStatus(), $order->getEmail())) {
                    continue;
                }

                $this->resendEmailService->resendOrderConfirmation($order->getId(), $order->getEventId(), $ip, self::AUDIT_USER_AGENT);
                $emails[] = self::maskEmail((string) $order->getEmail());
            }
        } catch (Throwable $exception) {
            $this->logger->error('Live chat: ticket resend failed', [
                'live_chat_session_id' => $session->getId(),
                'ticket_resend_request_id' => $request->getId(),
                'error' => $exception->getMessage(),
            ]);
            $emails = [];
        }

        if ($emails === []) {
            return new LiveChatTicketResendReplyDTO(
                messages: [__('I could not send your tickets again. I am passing this on to the organizer, who will look into it.', [], $locale)],
                needsFollowUp: true,
                reason: __('ticket resend: the tickets could not be sent again'),
            );
        }

        $this->logger->info('Live chat: tickets sent again', [
            'live_chat_session_id' => $session->getId(),
            'ticket_resend_request_id' => $request->getId(),
            'order_ids' => $orderIds,
        ]);

        return new LiveChatTicketResendReplyDTO([__('Done, I have just sent your tickets again to :email. Also check your spam folder.', [
            'email' => implode(', ', array_values(array_unique($emails))),
        ], $locale)]);
    }

    /**
     * A message made of a 6-digit code only (spaces allowed inside it), as typed after receiving the SMS.
     */
    public static function extractCode(string $message): ?string
    {
        if (mb_strlen($message) > 80) {
            return null;
        }

        $compact = preg_replace('/(?<=\d)[\s.\-](?=\d)/u', '', $message) ?? $message;
        if (preg_match_all('/\d+/', $compact, $numbers) !== 1 || strlen($numbers[0][0]) !== LiveChatVerificationCodeService::LENGTH) {
            return null;
        }

        return $numbers[0][0];
    }

    public static function normalizePhone(string $phone): ?string
    {
        $normalized = SmsPhoneNormalizer::normalize($phone);

        return $normalized !== null && preg_match('/^\+[1-9]\d{7,14}$/', $normalized) === 1 ? $normalized : null;
    }

    public static function normalizeName(string $name): string
    {
        $name = Str::lower(Str::ascii($name));
        $name = preg_replace('/[^a-z]+/', ' ', $name) ?? '';

        return trim($name);
    }

    /**
     * Same first name, accents and case aside, or the first of the given first names (« Awa » for « Awa Fatima »).
     *
     * @param  list<string>  $names
     */
    public static function firstNameMatches(string $given, array $names): bool
    {
        $given = self::normalizeName($given);
        if ($given === '') {
            return false;
        }

        foreach ($names as $name) {
            $name = self::normalizeName($name);
            if ($name === $given || str_starts_with($name, $given.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Paper tickets « Sur place » (no real e-mail, or still to be paid at the door) are never sent by e-mail.
     */
    public static function isResendable(?string $status, ?string $paymentStatus, ?string $email): bool
    {
        return $status === OrderStatus::COMPLETED->name
            && $paymentStatus !== OrderPaymentStatus::AWAITING_OFFLINE_PAYMENT->name
            && ! KioskSentinelEmail::isKioskSentinelEmail($email);
    }

    public static function maskEmail(string $email): string
    {
        $email = Str::lower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $dot = strrpos($domain, '.');
        $host = $dot !== false ? substr($domain, 0, $dot) : $domain;
        $tld = $dot !== false ? substr($domain, $dot) : '';

        return mb_substr($local, 0, 1).'***@'.($host !== '' ? mb_substr($host, 0, 1) : '').'***'.$tld;
    }

    /**
     * The model must not invent a first name or a number: both have to appear in what the visitor wrote.
     *
     * @param  list<string>  $visitorTexts
     */
    public static function writtenByVisitor(string $firstName, string $normalizedPhone, array $visitorTexts): bool
    {
        $name = self::normalizeName($firstName);
        if ($name === '') {
            return false;
        }

        $nameFound = false;
        $phoneFound = false;
        foreach ($visitorTexts as $text) {
            $nameFound = $nameFound || str_contains(' '.self::normalizeName($text).' ', ' '.$name.' ');

            $compact = preg_replace('/(?<=\d)[\s.\-()]+(?=\d)/u', '', $text) ?? $text;
            if (preg_match_all('/\+?\d{6,15}/', $compact, $numbers) > 0) {
                foreach ($numbers[0] as $number) {
                    $phoneFound = $phoneFound || self::normalizePhone($number) === $normalizedPhone;
                }
            }
        }

        return $nameFound && $phoneFound;
    }

    /**
     * Completed orders of the event of the chat (or of every event of the organizer) with this phone and this first name.
     *
     * @return Collection<int, LiveChatTicketResendCandidateDTO>
     */
    public function matchingOrders(LiveChatSessionDomainObject $session, string $firstName, string $normalizedPhone): Collection
    {
        $suffix = SmsPhoneNormalizer::matchingSuffix($normalizedPhone);
        if ($suffix === null) {
            return collect();
        }

        return $this->directoryRepository
            ->findOrdersWithPhoneSuffix($session->getOrganizerId(), $session->getEventId(), $suffix, self::CANDIDATE_LIMIT)
            ->filter(fn (LiveChatTicketResendCandidateDTO $order) => self::isResendable($order->status, $order->paymentStatus, $order->email)
                && collect($order->phones)->contains(fn (string $phone) => self::normalizePhone($phone) === $normalizedPhone)
                && self::firstNameMatches($firstName, $order->firstNames))
            ->take(self::MAX_ORDERS)
            ->values();
    }

    private function limitReached(LiveChatSessionDomainObject $session, string $phoneHash): bool
    {
        $sessionRequests = $this->requestRepository->countWhere([
            LiveChatTicketResendRequestDomainObjectAbstract::LIVE_CHAT_SESSION_ID => $session->getId(),
        ]);
        if ($sessionRequests >= self::MAX_REQUESTS_PER_SESSION) {
            return true;
        }

        $sessionPhoneRequests = $this->requestRepository->countWhere([
            LiveChatTicketResendRequestDomainObjectAbstract::LIVE_CHAT_SESSION_ID => $session->getId(),
            LiveChatTicketResendRequestDomainObjectAbstract::PHONE_HASH => $phoneHash,
        ]);
        if ($sessionPhoneRequests >= self::MAX_SENDS_PER_PHONE_PER_SESSION) {
            return true;
        }

        return $this->requestRepository->countWhere([
            LiveChatTicketResendRequestDomainObjectAbstract::PHONE_HASH => $phoneHash,
            [LiveChatTicketResendRequestDomainObjectAbstract::CREATED_AT, '>=', Carbon::now('UTC')->subDay()->toDateTimeString()],
        ]) >= self::MAX_SENDS_PER_PHONE_PER_DAY;
    }

    private function sendCode(
        LiveChatSessionDomainObject $session,
        LiveChatTicketResendRequestDomainObject $request,
        string $phone,
        string $code,
        string $locale,
    ): void {
        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $this->organizerRepository->findFirstWhere(['id' => $session->getOrganizerId()]);

        try {
            $this->smsSendService->send(new SendSmsDTO(
                organizerId: $session->getOrganizerId(),
                phone: $phone,
                content: __('Your code to receive your tickets (:organizer): :code. It is valid for :minutes minutes. Do not share it with anyone.', [
                    'organizer' => (string) $organizer?->getName(),
                    'code' => $code,
                    'minutes' => self::VALIDITY_MINUTES,
                ], $locale),
                authorType: SmsMessageAuthorType::AUTOMATIC,
            ));
        } catch (Throwable $exception) {
            $this->logger->warning('Live chat: ticket resend code could not be sent', [
                'live_chat_session_id' => $session->getId(),
                'ticket_resend_request_id' => $request->getId(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function pendingRequest(LiveChatSessionDomainObject $session): ?LiveChatTicketResendRequestDomainObject
    {
        return $this->requestRepository->findWhere(
            [
                LiveChatTicketResendRequestDomainObjectAbstract::LIVE_CHAT_SESSION_ID => $session->getId(),
                LiveChatTicketResendRequestDomainObjectAbstract::STATUS => LiveChatTicketResendStatus::CODE_SENT->name,
            ],
            orderAndDirections: [new OrderAndDirection(LiveChatTicketResendRequestDomainObjectAbstract::ID, OrderAndDirection::DIRECTION_DESC)],
        )->first();
    }

    private function setStatus(LiveChatTicketResendRequestDomainObject $request, LiveChatTicketResendStatus $status): void
    {
        $this->requestRepository->updateFromArray($request->getId(), [
            LiveChatTicketResendRequestDomainObjectAbstract::STATUS => $status->name,
            LiveChatTicketResendRequestDomainObjectAbstract::CODE_HASH => null,
        ]);
    }

    private function phoneHash(string $normalizedPhone): string
    {
        return $this->hmac('phone:'.$normalizedPhone);
    }

    private function hmac(string $value): string
    {
        return hash_hmac('sha256', $value, (string) $this->config->get('app.key'));
    }

    private function locale(LiveChatSessionDomainObject $session): string
    {
        return $this->localeResolver->forOrganizerId($session->getOrganizerId());
    }
}
