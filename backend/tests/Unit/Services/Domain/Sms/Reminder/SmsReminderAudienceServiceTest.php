<?php

namespace Tests\Unit\Services\Domain\Sms\Reminder;

use HiEvents\DomainObjects\SmsOptOutDomainObject;
use HiEvents\Repository\Interfaces\SmsCustomerDirectoryRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsOptOutRepositoryInterface;
use HiEvents\Services\Domain\Sms\DTO\SmsReminderAttendeeDTO;
use HiEvents\Services\Domain\Sms\Reminder\SmsReminderAudienceService;
use Mockery;
use Tests\TestCase;

class SmsReminderAudienceServiceTest extends TestCase
{
    public function test_keeps_one_reachable_recipient_per_number(): void
    {
        $directory = Mockery::mock(SmsCustomerDirectoryRepositoryInterface::class);
        $directory->shouldReceive('findActiveAttendeesWithPhone')->with(8)->andReturn(collect([
            new SmsReminderAttendeeDTO(1, '0639 12 34 56', 'Awa', 'Saïd'),
            new SmsReminderAttendeeDTO(2, '+262639123456', 'Fatima', 'Saïd'),
            new SmsReminderAttendeeDTO(3, null, 'Ali', null),
            new SmsReminderAttendeeDTO(4, '12', 'Moussa', null),
            new SmsReminderAttendeeDTO(5, '0639 00 00 01', 'Zaina', 'Ahmed'),
            new SmsReminderAttendeeDTO(6, '0612345678', null, null),
        ]));

        $optOuts = Mockery::mock(SmsOptOutRepositoryInterface::class);
        $optOuts->shouldReceive('findWhere')->with(['organizer_id' => 9])->andReturn(collect([
            (new SmsOptOutDomainObject)->setPhone('+262639000001'),
        ]));

        $audience = (new SmsReminderAudienceService($directory, $optOuts))->build(9, 8);

        $this->assertSame(6, $audience->activeAttendees);
        $this->assertSame(2, $audience->withoutValidPhone);
        $this->assertSame(1, $audience->optedOut);
        $this->assertSame(1, $audience->sharedPhone);
        $this->assertCount(2, $audience->recipients);

        [$first, $second] = $audience->recipients;
        $this->assertSame('+262639123456', $first->phone);
        $this->assertSame(1, $first->attendeeId);
        $this->assertSame('Awa', $first->firstName);
        $this->assertSame('Awa Saïd', $first->contactName);
        $this->assertSame('+33612345678', $second->phone);
        $this->assertNull($second->contactName);
    }
}
