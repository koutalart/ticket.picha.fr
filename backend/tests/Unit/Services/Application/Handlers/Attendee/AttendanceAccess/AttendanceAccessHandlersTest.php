<?php

namespace Tests\Unit\Services\Application\Handlers\Attendee\AttendanceAccess;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\AttendanceAccessRateLimitedException;
use HiEvents\Exceptions\AttendanceResponseNotAllowedException;
use HiEvents\Exceptions\InvalidAttendanceAccessCodeException;
use HiEvents\Exceptions\InvalidAttendanceAccessContactException;
use HiEvents\Helper\CustomerLocale;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Services\Application\Handlers\Attendee\AttendanceAccess\DTO\RequestAttendanceAccessCodeDTO;
use HiEvents\Services\Application\Handlers\Attendee\AttendanceAccess\DTO\VerifyAttendanceAccessCodeDTO;
use HiEvents\Services\Application\Handlers\Attendee\AttendanceAccess\RequestAttendanceAccessCodeHandler;
use HiEvents\Services\Application\Handlers\Attendee\AttendanceAccess\VerifyAttendanceAccessCodeHandler;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\AttendanceAccessCodeService;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\AttendanceAccessContactMatcher;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\AttendanceAccessContactParser;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\AttendanceAccessEventResolver;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\AttendanceAccessNotifier;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\AttendanceAccessRateLimiter;
use HiEvents\Services\Domain\Attendee\AttendeeAttendanceLinkGenerator;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class AttendanceAccessHandlersTest extends TestCase
{
    private AttendanceAccessEventResolver $eventResolver;

    private AttendanceAccessContactMatcher $matcher;

    private AttendanceAccessNotifier $notifier;

    private AttendeeRepositoryInterface $attendeeRepository;

    private AttendanceAccessCodeService $codeService;

    private AttendanceAccessRateLimiter $rateLimiter;

    private CustomerLocaleResolver $customerLocaleResolver;

    private array $logs = [];

    private EventDomainObject $event;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:dGVzdC1rZXktZm9yLWF0dGVuZGFuY2UtYWNjZXNz']);

        $this->event = (new EventDomainObject)->setId(4)->setOrganizerId(9)->setTitle('Concert');
        $this->eventResolver = m::mock(AttendanceAccessEventResolver::class);
        $this->eventResolver->shouldReceive('resolve')->with(4)->andReturn($this->event)->byDefault();
        config(['app.customer_locale' => 'fr']);
        $this->customerLocaleResolver = m::mock(CustomerLocaleResolver::class);
        $this->customerLocaleResolver->shouldReceive('forEvent')->with($this->event)->andReturn('fr')->byDefault();
        $this->matcher = m::mock(AttendanceAccessContactMatcher::class);
        $this->notifier = m::mock(AttendanceAccessNotifier::class);
        $this->attendeeRepository = m::mock(AttendeeRepositoryInterface::class);
        $this->codeService = new AttendanceAccessCodeService(new Repository(new ArrayStore));
        $this->rateLimiter = new AttendanceAccessRateLimiter(new RateLimiter(new Repository(new ArrayStore)));
    }

    public function test_unknown_contact_receives_nothing_and_the_call_looks_the_same(): void
    {
        $this->matcher->shouldReceive('findAttendeeIds')->once()->andReturn([]);
        $this->notifier->shouldNotReceive('send');

        $result = $this->requestHandler()->handle($this->requestDto('nobody@example.com'));

        $this->assertNull($result);
        $this->assertSame('no_match', $this->logs[0]['context']['result']);
    }

    public function test_known_contact_receives_a_code_and_the_call_looks_the_same(): void
    {
        $this->matcher->shouldReceive('findAttendeeIds')->once()->andReturn([12]);
        $this->notifier->shouldReceive('send')
            ->once()
            ->with($this->event, m::on(fn ($contact) => $contact->value === 'jo@example.com'), m::pattern('/^\d{6}$/'));

        $result = $this->requestHandler()->handle($this->requestDto('Jo@Example.com'));

        $this->assertNull($result);
        $this->assertSame('sent', $this->logs[0]['context']['result']);
    }

    public function test_logs_never_contain_the_contact_or_the_full_ip_in_clear(): void
    {
        $this->matcher->shouldReceive('findAttendeeIds')->andReturn([12]);
        $this->notifier->shouldReceive('send');

        $this->requestHandler()->handle($this->requestDto('jo@example.com'));
        $this->requestHandler()->handle($this->requestDto('0639000001'));

        $encoded = json_encode($this->logs);
        $this->assertStringNotContainsString('jo@example.com', $encoded);
        $this->assertStringNotContainsString('639000001', $encoded);
        $this->assertStringNotContainsString('203.0.113.42', $encoded);
        $this->assertStringContainsString('203.0.113.0', $encoded);
    }

    public function test_fourth_request_for_the_same_contact_is_rate_limited_whether_or_not_it_matches(): void
    {
        $this->matcher->shouldReceive('findAttendeeIds')->times(3)->andReturn([]);
        $this->notifier->shouldNotReceive('send');
        $handler = $this->requestHandler();

        for ($request = 0; $request < 3; $request++) {
            $handler->handle($this->requestDto('nobody@example.com'));
        }

        $this->expectException(AttendanceAccessRateLimitedException::class);
        $handler->handle($this->requestDto('nobody@example.com'));
    }

    public function test_disabled_setting_refuses_the_request(): void
    {
        $this->eventResolver->shouldReceive('resolve')->with(4)->andThrow(new AttendanceResponseNotAllowedException('off'));
        $this->matcher->shouldNotReceive('findAttendeeIds');

        $this->expectException(AttendanceResponseNotAllowedException::class);
        $this->requestHandler()->handle($this->requestDto('jo@example.com'));
    }

    public function test_valid_code_returns_the_active_attendee_links_only_once(): void
    {
        $contact = (new AttendanceAccessContactParser)->parse('jo@example.com');
        $code = $this->codeService->issue(4, $contact, [12, 13]);
        $attendee = (new AttendeeDomainObject)->setId(12)->setShortId('A-12')->setFirstName('Jo')->setEventId(4);

        $this->attendeeRepository->shouldReceive('findWhereIn')
            ->once()
            ->with('id', [12, 13], ['event_id' => 4, 'status' => 'ACTIVE'])
            ->andReturn(collect([$attendee]));

        $links = $this->verifyHandler()->handle($this->verifyDto('jo@example.com', $code));

        $this->assertCount(1, $links);
        $this->assertSame('A-12', $links->first()->short_id);
        $this->assertSame('Jo', $links->first()->first_name);
        $this->assertSame((new AttendeeAttendanceLinkGenerator)->generateCode($attendee), $links->first()->code);

        $this->expectException(InvalidAttendanceAccessCodeException::class);
        $this->verifyHandler()->handle($this->verifyDto('jo@example.com', $code));
    }

    public function test_wrong_code_is_rejected_and_logged(): void
    {
        $contact = (new AttendanceAccessContactParser)->parse('jo@example.com');
        $code = $this->codeService->issue(4, $contact, [12]);
        $this->attendeeRepository->shouldNotReceive('findWhereIn');

        try {
            $this->verifyHandler()->handle($this->verifyDto('jo@example.com', $code === '123456' ? '654321' : '123456'));
            $this->fail('A wrong code must be rejected');
        } catch (InvalidAttendanceAccessCodeException) {
        }

        $this->assertSame('invalid_code', $this->logs[0]['context']['result']);
    }

    public function test_errors_are_in_tu_when_the_organizer_chose_tu(): void
    {
        $this->customerLocaleResolver->shouldReceive('forEvent')->with($this->event)->andReturn(CustomerLocale::FRENCH_TUTOIEMENT);
        $this->matcher->shouldReceive('findAttendeeIds')->andReturn([]);

        $this->assertSame(
            "Saisis l'adresse e-mail ou le numéro de téléphone utilisé lors de l'inscription.",
            $this->exceptionMessage(fn () => $this->requestHandler()->handle($this->requestDto('pas-un-contact'))),
        );
        $this->assertSame(
            'Ce code est incorrect ou a expiré. Demande un nouveau code.',
            $this->exceptionMessage(fn () => $this->verifyHandler()->handle($this->verifyDto('jo@example.com', '123456'))),
        );

        for ($request = 0; $request < 3; $request++) {
            $this->requestHandler()->handle($this->requestDto('nobody@example.com'));
        }
        $this->assertSame(
            'Trop de tentatives. Réessaie dans une heure.',
            $this->exceptionMessage(fn () => $this->requestHandler()->handle($this->requestDto('nobody@example.com'))),
        );
    }

    public function test_errors_keep_vous_otherwise(): void
    {
        $this->assertSame(
            "Saisissez l'adresse e-mail ou le numéro de téléphone utilisé lors de l'inscription.",
            $this->exceptionMessage(fn () => $this->requestHandler()->handle($this->requestDto('pas-un-contact'))),
        );
        $this->assertSame(
            'Ce code est incorrect ou a expiré. Demandez un nouveau code.',
            $this->exceptionMessage(fn () => $this->verifyHandler()->handle($this->verifyDto('jo@example.com', '123456'))),
        );
    }

    private function exceptionMessage(callable $callback): string
    {
        try {
            $callback();
        } catch (InvalidAttendanceAccessContactException|InvalidAttendanceAccessCodeException|AttendanceAccessRateLimitedException $exception) {
            return $exception->getMessage();
        }

        $this->fail('An error was expected');
    }

    private function requestHandler(): RequestAttendanceAccessCodeHandler
    {
        return new RequestAttendanceAccessCodeHandler(
            $this->eventResolver,
            new AttendanceAccessContactParser,
            $this->rateLimiter,
            $this->matcher,
            $this->codeService,
            $this->notifier,
            $this->logger(),
            $this->customerLocaleResolver,
        );
    }

    private function verifyHandler(): VerifyAttendanceAccessCodeHandler
    {
        return new VerifyAttendanceAccessCodeHandler(
            $this->eventResolver,
            new AttendanceAccessContactParser,
            $this->rateLimiter,
            $this->codeService,
            $this->attendeeRepository,
            new AttendeeAttendanceLinkGenerator,
            $this->logger(),
            $this->customerLocaleResolver,
        );
    }

    private function logger(): LoggerInterface
    {
        $logger = m::mock(LoggerInterface::class);
        $record = function (string $message, array $context = []) {
            $this->logs[] = ['message' => $message, 'context' => $context];
        };
        $logger->shouldReceive('info')->andReturnUsing($record);
        $logger->shouldReceive('warning')->andReturnUsing($record);

        return $logger;
    }

    private function requestDto(string $contact): RequestAttendanceAccessCodeDTO
    {
        return new RequestAttendanceAccessCodeDTO(eventId: 4, contact: $contact, ipAddress: '203.0.113.42');
    }

    private function verifyDto(string $contact, string $code): VerifyAttendanceAccessCodeDTO
    {
        return new VerifyAttendanceAccessCodeDTO(eventId: 4, contact: $contact, code: $code, ipAddress: '203.0.113.42');
    }
}
