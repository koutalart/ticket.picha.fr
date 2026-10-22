<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Attendee\AttendanceAccess;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeDomainObjectAbstract;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\Exceptions\AttendanceAccessRateLimitedException;
use HiEvents\Exceptions\AttendanceResponseNotAllowedException;
use HiEvents\Exceptions\InvalidAttendanceAccessCodeException;
use HiEvents\Exceptions\InvalidAttendanceAccessContactException;
use HiEvents\Helper\IpAddressMasker;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\AttendanceAccess\DTO\AttendanceAccessLinkDTO;
use HiEvents\Services\Application\Handlers\Attendee\AttendanceAccess\DTO\VerifyAttendanceAccessCodeDTO;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\AttendanceAccessCodeService;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\AttendanceAccessContactParser;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\AttendanceAccessEventResolver;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\AttendanceAccessRateLimiter;
use HiEvents\Services\Domain\Attendee\AttendeeAttendanceLinkGenerator;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

class VerifyAttendanceAccessCodeHandler
{
    public function __construct(
        private readonly AttendanceAccessEventResolver $eventResolver,
        private readonly AttendanceAccessContactParser $contactParser,
        private readonly AttendanceAccessRateLimiter $rateLimiter,
        private readonly AttendanceAccessCodeService $codeService,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly AttendeeAttendanceLinkGenerator $linkGenerator,
        private readonly LoggerInterface $logger,
        private readonly CustomerLocaleResolver $customerLocaleResolver,
    ) {}

    /**
     * @return Collection<int, AttendanceAccessLinkDTO>
     *
     * @throws AttendanceResponseNotAllowedException
     * @throws InvalidAttendanceAccessContactException
     * @throws InvalidAttendanceAccessCodeException
     * @throws AttendanceAccessRateLimitedException
     */
    public function handle(VerifyAttendanceAccessCodeDTO $data): Collection
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
            $this->rateLimiter->hitVerification($data->ipAddress, $locale);
            $attendeeIds = $this->codeService->consume($data->eventId, $contact, $data->code, $locale);
        } catch (AttendanceAccessRateLimitedException|InvalidAttendanceAccessCodeException $exception) {
            $this->logger->warning('Attendance access code rejected', $context + [
                'result' => $exception instanceof AttendanceAccessRateLimitedException ? 'rate_limited' : 'invalid_code',
            ]);
            throw $exception;
        }

        $links = $this->attendeeRepository
            ->findWhereIn(AttendeeDomainObjectAbstract::ID, $attendeeIds, [
                AttendeeDomainObjectAbstract::EVENT_ID => $data->eventId,
                AttendeeDomainObjectAbstract::STATUS => AttendeeStatus::ACTIVE->name,
            ])
            ->map(fn (AttendeeDomainObject $attendee) => new AttendanceAccessLinkDTO(
                short_id: $attendee->getShortId(),
                first_name: (string) $attendee->getFirstName(),
                code: $this->linkGenerator->generateCode($attendee),
            ))
            ->values();

        $this->logger->info('Attendance access code verified', $context + [
            'result' => 'verified',
            'attendee_count' => $links->count(),
        ]);

        return $links;
    }
}
