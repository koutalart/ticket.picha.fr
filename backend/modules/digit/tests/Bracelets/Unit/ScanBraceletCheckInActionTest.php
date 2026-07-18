<?php

declare(strict_types=1);

namespace Digit\Tests\Bracelets\Unit;

use Digit\Bracelets\Domain\Enums\BraceletStatus;
use Digit\Bracelets\Domain\Exceptions\BraceletEventMismatchException;
use Digit\Bracelets\Domain\Exceptions\BraceletNotAssignedException;
use Digit\Bracelets\Domain\Exceptions\BraceletNotFoundException;
use Digit\Bracelets\Domain\Exceptions\InvalidBraceletPayloadException;
use Digit\Bracelets\Domain\Services\BraceletLookupService;
use Digit\Bracelets\Http\Actions\ScanBraceletCheckInAction;
use Digit\Scan\Domain\DTO\ScanOutcomeDTO;
use Digit\Scan\Domain\Services\ScanCoordinatorService;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use Illuminate\Http\Request;
use Mockery as m;
use Tests\TestCase;

/**
 * Verifies the orchestration/error-mapping in ScanBraceletCheckInAction
 * only - BraceletLookupService's own logic (signature verification order,
 * status checks) is already covered by BraceletLookupServiceTest. This
 * test proves the Action wires it to ScanCoordinatorService correctly and
 * maps each distinct rejection reason to its own HTTP status, without
 * ever conflating "bad request" with "scan pipeline result".
 */
class ScanBraceletCheckInActionTest extends TestCase
{
    private BraceletLookupService $lookupService;
    private ScanCoordinatorService $coordinator;
    private CheckInListRepositoryInterface $checkInListRepository;
    private AttendeeRepositoryInterface $attendeeRepository;
    private ScanBraceletCheckInAction $action;
    private CheckInListDomainObject $checkInList;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lookupService = m::mock(BraceletLookupService::class);
        $this->coordinator = m::mock(ScanCoordinatorService::class);
        $this->checkInListRepository = m::mock(CheckInListRepositoryInterface::class);
        $this->attendeeRepository = m::mock(AttendeeRepositoryInterface::class);

        $this->action = new ScanBraceletCheckInAction(
            $this->lookupService,
            $this->coordinator,
            $this->checkInListRepository,
            $this->attendeeRepository,
        );

        $this->checkInList = m::mock(CheckInListDomainObject::class);
        $this->checkInList->shouldReceive('getId')->andReturn(42);
        $this->checkInList->shouldReceive('getShortId')->andReturn('cil_short_id');
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    private function makeDevice(?int $eventId = 7): object
    {
        return (object) ['id' => 1, 'name' => 'Entrance A', 'event_id' => $eventId];
    }

    private function makeRequest(array $input, ?object $device): Request
    {
        $request = Request::create('/digit/scan/bracelets/cil_short_id/check-ins', 'POST', $input);
        if ($device !== null) {
            $request->attributes->set('digit_scan_device', $device);
        }
        return $request;
    }

    public function testReturnsNotFoundWhenCheckInListUnknown(): void
    {
        $this->checkInListRepository->shouldReceive('findFirstWhere')->once()->andReturnNull();

        $response = ($this->action)('unknown_short_id', $this->makeRequest(['payload' => 'DGT1.x.y'], $this->makeDevice()));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testReturnsUnprocessableWhenPayloadMissing(): void
    {
        $this->checkInListRepository->shouldReceive('findFirstWhere')->once()->andReturn($this->checkInList);

        $response = ($this->action)('cil_short_id', $this->makeRequest([], $this->makeDevice()));

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testReturnsForbiddenWhenDeviceHasNoEventScope(): void
    {
        $this->checkInListRepository->shouldReceive('findFirstWhere')->once()->andReturn($this->checkInList);

        $response = ($this->action)('cil_short_id', $this->makeRequest(['payload' => 'DGT1.x.y'], $this->makeDevice(eventId: null)));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testReturnsUnprocessableOnInvalidPayload(): void
    {
        $this->checkInListRepository->shouldReceive('findFirstWhere')->once()->andReturn($this->checkInList);
        $this->lookupService->shouldReceive('resolveAttendeeId')->once()
            ->andThrow(new InvalidBraceletPayloadException('bad signature'));

        $response = ($this->action)('cil_short_id', $this->makeRequest(['payload' => 'DGT1.x.y'], $this->makeDevice()));

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testReturnsNotFoundOnUnknownBracelet(): void
    {
        $this->checkInListRepository->shouldReceive('findFirstWhere')->once()->andReturn($this->checkInList);
        $this->lookupService->shouldReceive('resolveAttendeeId')->once()
            ->andThrow(new BraceletNotFoundException('no such code'));

        $response = ($this->action)('cil_short_id', $this->makeRequest(['payload' => 'DGT1.x.y'], $this->makeDevice()));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testReturnsForbiddenOnEventMismatch(): void
    {
        $this->checkInListRepository->shouldReceive('findFirstWhere')->once()->andReturn($this->checkInList);
        $this->lookupService->shouldReceive('resolveAttendeeId')->once()
            ->andThrow(new BraceletEventMismatchException('wrong event'));

        $response = ($this->action)('cil_short_id', $this->makeRequest(['payload' => 'DGT1.x.y'], $this->makeDevice()));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testReturnsUnprocessableWhenBraceletNotAssigned(): void
    {
        $this->checkInListRepository->shouldReceive('findFirstWhere')->once()->andReturn($this->checkInList);
        $this->lookupService->shouldReceive('resolveAttendeeId')->once()
            ->andThrow(new BraceletNotAssignedException(BraceletStatus::REVOKED));

        $response = ($this->action)('cil_short_id', $this->makeRequest(['payload' => 'DGT1.x.y'], $this->makeDevice()));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('bracelet_not_assigned:REVOKED', $response->getContent());
    }

    public function testHappyPathCallsCoordinatorWithResolvedAttendeeAndReturnsOk(): void
    {
        $this->checkInListRepository->shouldReceive('findFirstWhere')->once()->andReturn($this->checkInList);
        $this->lookupService->shouldReceive('resolveAttendeeId')->once()
            ->with('DGT1.code.sig', 7)
            ->andReturn(99);

        $attendee = m::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getPublicId')->andReturn('attendee-public-id');
        $this->attendeeRepository->shouldReceive('findById')->once()->with(99)->andReturn($attendee);

        $this->coordinator->shouldReceive('scan')->once()
            ->withArgs(function (string $checkInListUuid, int $checkInListId, int $attendeeId, string $attendeePublicId) {
                return $checkInListUuid === 'cil_short_id'
                    && $checkInListId === 42
                    && $attendeeId === 99
                    && $attendeePublicId === 'attendee-public-id';
            })
            ->andReturn(ScanOutcomeDTO::success(99, 555));

        $response = ($this->action)('cil_short_id', $this->makeRequest(['payload' => 'DGT1.code.sig'], $this->makeDevice()));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('"result":"recorded"', $response->getContent());
        $this->assertStringContainsString('"attendee_id":99', $response->getContent());
    }

    public function testDuplicateResultMapsToConflict(): void
    {
        $this->checkInListRepository->shouldReceive('findFirstWhere')->once()->andReturn($this->checkInList);
        $this->lookupService->shouldReceive('resolveAttendeeId')->once()->andReturn(99);

        $attendee = m::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getPublicId')->andReturn('attendee-public-id');
        $this->attendeeRepository->shouldReceive('findById')->once()->andReturn($attendee);

        $this->coordinator->shouldReceive('scan')->once()->andReturn(ScanOutcomeDTO::duplicate(99));

        $response = ($this->action)('cil_short_id', $this->makeRequest(['payload' => 'DGT1.code.sig'], $this->makeDevice()));

        $this->assertSame(409, $response->getStatusCode());
    }
}
