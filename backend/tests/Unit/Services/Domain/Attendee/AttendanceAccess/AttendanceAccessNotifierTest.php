<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Attendee\AttendanceAccess;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\AttendanceAccessChannel;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Mail\Attendee\AttendanceAccessCodeMail;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\AttendanceAccessNotifier;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\DTO\AttendanceAccessContactDTO;
use HiEvents\Services\Domain\Customer\CustomerLocaleResolver;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Collection;
use Mockery as m;
use Tests\TestCase;

class AttendanceAccessNotifierTest extends TestCase
{
    private AttendeeRepositoryInterface $attendeeRepository;

    private OrderRepositoryInterface $orderRepository;

    private ?AttendanceAccessCodeMail $sent = null;

    private function notifier(): AttendanceAccessNotifier
    {
        $pendingMail = m::mock('PendingMail');
        $pendingMail->shouldReceive('locale')->andReturnSelf();
        $pendingMail->shouldReceive('queue')->andReturnUsing(function (AttendanceAccessCodeMail $mail) {
            $this->sent = $mail;
        });
        $mailer = m::mock(Mailer::class);
        $mailer->shouldReceive('to')->andReturn($pendingMail);

        $locale = m::mock(CustomerLocaleResolver::class);
        $locale->shouldReceive('forEvent')->andReturn('fr');

        $organizerRepository = m::mock(OrganizerRepositoryInterface::class);
        $organizerRepository->shouldReceive('findFirst')->with(8)->andReturn((new OrganizerDomainObject)->setId(8)->setName("Kultur'm"));

        return new AttendanceAccessNotifier(
            $mailer,
            m::mock(Dispatcher::class),
            $locale,
            $this->attendeeRepository,
            $this->orderRepository,
            $organizerRepository,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->attendeeRepository = m::mock(AttendeeRepositoryInterface::class);
        $this->orderRepository = m::mock(OrderRepositoryInterface::class);
    }

    private function event(): EventDomainObject
    {
        return (new EventDomainObject)->setId(14)->setOrganizerId(8)->setTitle('Le Concert des Générations');
    }

    private function contact(): AttendanceAccessContactDTO
    {
        return new AttendanceAccessContactDTO(AttendanceAccessChannel::EMAIL, 'elodie@exemple.test');
    }

    private function attendee(int $id, string $email, string $firstName): AttendeeDomainObject
    {
        return (new AttendeeDomainObject)->setId($id)->setOrderId(30)->setEmail($email)->setFirstName($firstName);
    }

    public function test_greets_the_attendee_registered_with_the_e_mail_and_signs_as_the_organizer(): void
    {
        $this->attendeeRepository->shouldReceive('findWhereIn')->andReturn(new Collection([
            $this->attendee(1, 'awa@exemple.test', 'Awa'),
            $this->attendee(2, 'Elodie@Exemple.test', 'Élodie'),
        ]));

        $this->notifier()->send($this->event(), $this->contact(), '482913', [1, 2]);

        self::assertSame('Élodie', $this->sent->content()->with['firstName']);
        self::assertSame("Kultur'm", $this->sent->content()->with['organizerName']);
    }

    public function test_falls_back_on_the_first_name_of_the_order_made_with_the_e_mail(): void
    {
        $this->attendeeRepository->shouldReceive('findWhereIn')->andReturn(new Collection([
            $this->attendee(1, 'awa@exemple.test', 'Awa'),
        ]));
        $this->orderRepository->shouldReceive('findWhereIn')->andReturn(new Collection([
            (new OrderDomainObject)->setId(30)->setEmail('elodie@exemple.test')->setFirstName('Élodie'),
        ]));

        $this->notifier()->send($this->event(), $this->contact(), '482913', [1]);

        self::assertSame('Élodie', $this->sent->content()->with['firstName']);
    }

    public function test_no_first_name_without_matched_registration(): void
    {
        $this->attendeeRepository->shouldNotReceive('findWhereIn');

        $this->notifier()->send($this->event(), $this->contact(), '482913');

        self::assertNull($this->sent->content()->with['firstName']);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
