<?php

namespace Tests\Unit\Services\Infrastructure\Authorization;

use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventViewerDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventViewerRepositoryInterface;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Auth\AuthManager;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class EventViewerAuthorizationTest extends TestCase
{
    private const ACCOUNT_ID = 5;

    private const EVENT_ID = 14;

    private EventRepositoryInterface $eventRepository;

    private EventViewerRepositoryInterface $eventViewerRepository;

    private Request $request;

    private function service(string $method, string $path, ?EventViewerDomainObject $assignment, int $eventAccountId = self::ACCOUNT_ID): IsAuthorizedService
    {
        $this->request = Request::create($path, $method);
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->eventRepository->shouldReceive('findById')
            ->andReturn((new EventDomainObject)->setId(self::EVENT_ID)->setAccountId($eventAccountId));
        $this->eventViewerRepository = Mockery::mock(EventViewerRepositoryInterface::class);
        $this->eventViewerRepository->shouldReceive('findFirstWhere')->andReturn($assignment);

        $app = Mockery::mock(Application::class);
        $app->shouldReceive('make')->with('request')->andReturn($this->request);
        $app->shouldReceive('make')->with(EventRepositoryInterface::class)->andReturn($this->eventRepository);
        $app->shouldReceive('make')->with(EventViewerRepositoryInterface::class)->andReturn($this->eventViewerRepository);

        return new IsAuthorizedService(
            $app,
            Mockery::mock(AccountUserRepositoryInterface::class),
            Mockery::mock(AuthManager::class),
        );
    }

    private function user(string $role = 'EVENT_VIEWER'): UserDomainObject
    {
        return (new UserDomainObject)
            ->setId(18)
            ->setCurrentAccountUser((new AccountUserDomainObject)
                ->setAccountId(self::ACCOUNT_ID)
                ->setRole($role)
                ->setStatus(UserStatus::ACTIVE->name));
    }

    private function activeAssignment(): EventViewerDomainObject
    {
        return (new EventViewerDomainObject)->setEventId(self::EVENT_ID)->setUserId(18);
    }

    public function test_viewer_can_list_attendees_of_assigned_event(): void
    {
        $this->service('GET', '/api/events/14/attendees', $this->activeAssignment())
            ->isActionAuthorized(self::EVENT_ID, EventDomainObject::class, $this->user(), self::ACCOUNT_ID, Role::ORGANIZER);

        $this->assertTrue(true);
    }

    public function test_viewer_can_export_orders_of_assigned_event(): void
    {
        $this->service('POST', '/api/events/14/orders/export', $this->activeAssignment())
            ->isActionAuthorized(self::EVENT_ID, EventDomainObject::class, $this->user(), self::ACCOUNT_ID, Role::ORGANIZER);

        $this->assertTrue(true);
    }

    public function test_viewer_cannot_edit_an_attendee(): void
    {
        $this->expectException(UnauthorizedException::class);

        $this->service('PUT', '/api/events/14/attendees/3', $this->activeAssignment())
            ->isActionAuthorized(self::EVENT_ID, EventDomainObject::class, $this->user(), self::ACCOUNT_ID, Role::ORGANIZER);
    }

    public function test_viewer_cannot_delete_the_event(): void
    {
        $this->expectException(UnauthorizedException::class);

        $this->service('DELETE', '/api/events/14', $this->activeAssignment())
            ->isActionAuthorized(self::EVENT_ID, EventDomainObject::class, $this->user(), self::ACCOUNT_ID, Role::ORGANIZER);
    }

    public function test_viewer_cannot_read_settings_outside_the_allowlist(): void
    {
        $this->expectException(UnauthorizedException::class);

        $this->service('GET', '/api/events/14/settings', $this->activeAssignment())
            ->isActionAuthorized(self::EVENT_ID, EventDomainObject::class, $this->user(), self::ACCOUNT_ID, Role::ORGANIZER);
    }

    public function test_viewer_cannot_read_another_event(): void
    {
        $this->expectException(UnauthorizedException::class);

        $this->service('GET', '/api/events/15/attendees', null)
            ->isActionAuthorized(15, EventDomainObject::class, $this->user(), self::ACCOUNT_ID, Role::ORGANIZER);
    }

    public function test_viewer_cannot_use_an_id_that_differs_from_the_url(): void
    {
        $this->expectException(UnauthorizedException::class);

        $this->service('GET', '/api/events/15/attendees', $this->activeAssignment())
            ->isActionAuthorized(self::EVENT_ID, EventDomainObject::class, $this->user(), self::ACCOUNT_ID, Role::ORGANIZER);
    }

    public function test_revoked_viewer_is_refused(): void
    {
        $this->expectException(UnauthorizedException::class);

        $this->service('GET', '/api/events/14/attendees', null)
            ->isActionAuthorized(self::EVENT_ID, EventDomainObject::class, $this->user(), self::ACCOUNT_ID, Role::ORGANIZER);
    }

    public function test_viewer_cannot_reach_an_event_of_another_account(): void
    {
        $this->expectException(UnauthorizedException::class);

        $this->service('GET', '/api/events/14/attendees', $this->activeAssignment(), eventAccountId: 99)
            ->isActionAuthorized(self::EVENT_ID, EventDomainObject::class, $this->user(), self::ACCOUNT_ID, Role::ORGANIZER);
    }

    public function test_viewer_cannot_reach_organizer_endpoints(): void
    {
        $this->expectException(UnauthorizedException::class);

        $this->service('GET', '/api/organizers/4/stats', $this->activeAssignment())
            ->isActionAuthorized(4, OrganizerDomainObject::class, $this->user(), self::ACCOUNT_ID, Role::ORGANIZER);
    }

    public function test_viewer_is_refused_by_the_role_floor(): void
    {
        $this->expectException(UnauthorizedException::class);

        $this->service('GET', '/api/events', null)->validateUserRole(Role::ORGANIZER, $this->user());
    }

    public function test_viewer_passes_its_own_floor(): void
    {
        $this->service('GET', '/api/viewer/events', null)->validateUserRole(Role::EVENT_VIEWER, $this->user());

        $this->assertTrue(true);
    }
}
