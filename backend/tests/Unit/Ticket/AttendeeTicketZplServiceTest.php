<?php

declare(strict_types=1);

namespace Tests\Unit\Ticket;

use HiEvents\Services\Domain\Ticket\AttendeeTicketZplService;
use Tests\TestCase;

class AttendeeTicketZplServiceTest extends TestCase
{
    public function test_zpl_contains_qr_payload_and_ticket_fields(): void
    {
        $zpl = (new AttendeeTicketZplService())->generate(
            publicId: 'a_TESTPUBLICID',
            eventTitle: 'Mayotte',
            productTitle: 'PASS 1 JOUR',
            attendeeName: 'Jane Doe',
        );

        self::assertStringStartsWith('^XA', $zpl);
        self::assertStringEndsWith('^XZ', trim($zpl));
        self::assertStringContainsString('^BQN,2,5', $zpl);
        self::assertStringContainsString('QA,a_TESTPUBLICID', $zpl);
        self::assertStringContainsString('Mayotte', $zpl);
        self::assertStringContainsString('PASS 1 JOUR', $zpl);
        self::assertStringContainsString('Jane Doe', $zpl);
    }

    public function test_zpl_escapes_control_characters_in_user_text(): void
    {
        $zpl = (new AttendeeTicketZplService())->generate(
            publicId: 'a_SAFE',
            eventTitle: 'Night^Out',
            productTitle: 'VIP~Pass',
            attendeeName: 'Ann\\e',
        );

        self::assertStringNotContainsString('Night^Out', $zpl);
        self::assertStringContainsString('Night Out', $zpl);
        self::assertStringContainsString('VIP Pass', $zpl);
        self::assertStringContainsString('Ann e', $zpl);
        self::assertStringContainsString('QA,a_SAFE', $zpl);
    }
}
