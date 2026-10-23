<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Attendee\AttendanceAccess;

use HiEvents\Exceptions\AttendanceAccessRateLimitedException;
use HiEvents\Exceptions\AttendanceResponseNotAllowedException;
use HiEvents\Exceptions\InvalidAttendanceAccessContactException;
use HiEvents\Helper\IpAddressMasker;
use HiEvents\Services\Application\Handlers\Attendee\AttendanceAccess\DTO\RequestAttendanceAccessCodeDTO;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\AttendanceAccessCodeService;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\AttendanceAccessContactMatcher;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\AttendanceAccessContactParser;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\AttendanceAccessEventResolver;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\AttendanceAccessNotifier;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\AttendanceAccessRateLimiter;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use Psr\Log\LoggerInterface;

class RequestAttendanceAccessCodeHandler
{
    public function __construct(
        private readonly AttendanceAccessEventResolver $eventResolver,
        private readonly AttendanceAccessContactParser $contactParser,
        private readonly AttendanceAccessRateLimiter $rateLimiter,
        private readonly AttendanceAccessContactMatcher $contactMatcher,
        private readonly AttendanceAccessCodeService $codeService,
        private readonly AttendanceAccessNotifier $notifier,
        private readonly LoggerInterface $logger,
        private readonly CustomerLocaleResolver $customerLocaleResolver,
    ) {}

    /**
     * Same outcome for the caller whether or not the contact is registered.
     *
     * @throws AttendanceResponseNotAllowedException
     * @throws InvalidAttendanceAccessContactException
     * @throws AttendanceAccessRateLimitedException
     */
    public function handle(RequestAttendanceAccessCodeDTO $data): void
    {
        $event = $this->eventResolver->resolve($data->eventId);
        $locale = $this->customerLocaleResolver->forEvent($event);
        $contact = $this->contactParser->parse($data->contact, $locale);
        $context = [
            'event_id' => $data->eventId,
            'channel' => $contact->channel->value,
            'contact' => $contact->masked(),
            'ip' => IpAddressMasker::mask($data->ipAddress),
        ];

        try {
            $this->rateLimiter->hitCodeRequest($this->codeService->contactKey($data->eventId, $contact), $data->ipAddress, $locale);
        } catch (AttendanceAccessRateLimitedException $exception) {
            $this->logger->warning('Attendance access code request rate limited', $context + ['result' => 'rate_limited']);
            throw $exception;
        }

        $attendeeIds = $this->contactMatcher->findAttendeeIds($data->eventId, $contact);

        if ($attendeeIds === []) {
            $this->logger->info('Attendance access code requested', $context + ['result' => 'no_match']);

            return;
        }

        $code = $this->codeService->issue($data->eventId, $contact, $attendeeIds);
        $this->notifier->send($event, $contact, $code);

        $this->logger->info('Attendance access code requested', $context + [
            'result' => 'sent',
            'attendee_count' => count($attendeeIds),
        ]);
    }
}
