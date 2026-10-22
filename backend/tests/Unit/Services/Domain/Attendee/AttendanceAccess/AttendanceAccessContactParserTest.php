<?php

namespace Tests\Unit\Services\Domain\Attendee\AttendanceAccess;

use HiEvents\DomainObjects\Enums\AttendanceAccessChannel;
use HiEvents\Exceptions\InvalidAttendanceAccessContactException;
use HiEvents\Services\Domain\Attendee\AttendanceAccess\AttendanceAccessContactParser;
use Tests\TestCase;

class AttendanceAccessContactParserTest extends TestCase
{
    private AttendanceAccessContactParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new AttendanceAccessContactParser;
    }

    public function test_email_is_trimmed_and_lowercased(): void
    {
        $contact = $this->parser->parse('  Jo.Koutala@Example.COM ');

        $this->assertSame(AttendanceAccessChannel::EMAIL, $contact->channel);
        $this->assertSame('jo.koutala@example.com', $contact->value);
        $this->assertSame('j***@e***.com', $contact->masked());
    }

    public function test_mayotte_phone_is_normalized(): void
    {
        $local = $this->parser->parse('06 39 00 00 01');
        $international = $this->parser->parse('+262 639 00 00 01');

        $this->assertSame(AttendanceAccessChannel::PHONE, $local->channel);
        $this->assertSame('+262639000001', $local->value);
        $this->assertSame($local->value, $international->value);
        $this->assertSame('+262*******01', $local->masked());
    }

    public function test_invalid_contact_is_rejected(): void
    {
        $this->expectException(InvalidAttendanceAccessContactException::class);
        $this->parser->parse('jean dupont');
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->expectException(InvalidAttendanceAccessContactException::class);
        $this->parser->parse('jo@');
    }
}
