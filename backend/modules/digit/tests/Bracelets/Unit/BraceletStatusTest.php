<?php

declare(strict_types=1);

namespace Digit\Tests\Bracelets\Unit;

use Digit\Bracelets\Domain\Enums\BraceletStatus;
use PHPUnit\Framework\TestCase;

class BraceletStatusTest extends TestCase
{
    public function testOnlyAssignedIsValidForEntry(): void
    {
        $this->assertTrue(BraceletStatus::ASSIGNED->isValidForEntry());

        foreach ([BraceletStatus::GENERATED, BraceletStatus::PRINTED, BraceletStatus::REVOKED, BraceletStatus::COMPROMISED] as $status) {
            $this->assertFalse($status->isValidForEntry(), "{$status->value} should not be valid for entry");
        }
    }

    public function testRevokedAndCompromisedAreTerminal(): void
    {
        $this->assertTrue(BraceletStatus::REVOKED->isTerminal());
        $this->assertTrue(BraceletStatus::COMPROMISED->isTerminal());
        $this->assertFalse(BraceletStatus::GENERATED->isTerminal());
        $this->assertFalse(BraceletStatus::PRINTED->isTerminal());
        $this->assertFalse(BraceletStatus::ASSIGNED->isTerminal());
    }

    public function testOnlyGeneratedAndPrintedCanAssociate(): void
    {
        $this->assertTrue(BraceletStatus::GENERATED->canAssociate());
        $this->assertTrue(BraceletStatus::PRINTED->canAssociate());
        $this->assertFalse(BraceletStatus::ASSIGNED->canAssociate());
        $this->assertFalse(BraceletStatus::REVOKED->canAssociate());
        $this->assertFalse(BraceletStatus::COMPROMISED->canAssociate());
    }

    public function testNoCheckedInStatusExists(): void
    {
        $values = array_map(fn (BraceletStatus $s) => $s->value, BraceletStatus::cases());

        foreach (['CHECKED_IN', 'USED', 'CONSUMED'] as $forbidden) {
            $this->assertNotContains($forbidden, $values, 'Check-in state must live only in attendee_check_ins, never on the bracelet.');
        }
    }
}
