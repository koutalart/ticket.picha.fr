<?php

declare(strict_types=1);

namespace Digit\Tests\Bracelets\Feature;

use Digit\Bracelets\Domain\DTO\AssociateBraceletDTO;
use Digit\Bracelets\Domain\Enums\BraceletStatus;
use Digit\Bracelets\Domain\Exceptions\BraceletAssociationException;
use Digit\Bracelets\Domain\Models\DigitBracelet;
use Digit\Bracelets\Domain\Services\BraceletAssociationService;
use Digit\Tests\Bracelets\Support\InMemorySqliteTestCase;

/**
 * Run (once the project's PHP/Composer environment is available):
 *   ./vendor/bin/phpunit modules/digit/tests/Bracelets/Feature/BraceletAssociationServiceTest.php
 *
 * Uses only this module's own tables (see InMemorySqliteTestCase) - no
 * native Hi.Events fixtures/factories required.
 */
class BraceletAssociationServiceTest extends InMemorySqliteTestCase
{
    private const EVENT_ID = 1;
    private const ACCOUNT_ID = 1;

    private BraceletAssociationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BraceletAssociationService();
    }

    private function makeBracelet(string $code, BraceletStatus $status = BraceletStatus::GENERATED, ?int $attendeeId = null): DigitBracelet
    {
        return DigitBracelet::query()->create([
            'code' => $code,
            'event_id' => self::EVENT_ID,
            'account_id' => self::ACCOUNT_ID,
            'attendee_id' => $attendeeId,
            'status' => $status->value,
        ]);
    }

    public function testAssociateSucceedsFromGenerated(): void
    {
        $this->makeBracelet('BR-TEST-001');

        $result = $this->service->associate(new AssociateBraceletDTO(
            code: 'BR-TEST-001',
            attendeeId: 42,
            eventId: self::EVENT_ID,
        ));

        $this->assertSame(BraceletStatus::ASSIGNED, $result->status);
        $this->assertSame(42, $result->attendeeId);
        $this->assertNotNull($result->assignedAt);
    }

    public function testAssociateFailsIfAlreadyAssigned(): void
    {
        $this->makeBracelet('BR-TEST-002', BraceletStatus::ASSIGNED, attendeeId: 7);

        $this->expectException(BraceletAssociationException::class);

        $this->service->associate(new AssociateBraceletDTO(
            code: 'BR-TEST-002',
            attendeeId: 99,
            eventId: self::EVENT_ID,
        ));
    }

    public function testAttendeeCannotHaveTwoActiveBracelets(): void
    {
        $this->makeBracelet('BR-TEST-003', BraceletStatus::ASSIGNED, attendeeId: 7);
        $this->makeBracelet('BR-TEST-004');

        $this->expectException(BraceletAssociationException::class);

        $this->service->associate(new AssociateBraceletDTO(
            code: 'BR-TEST-004',
            attendeeId: 7,
            eventId: self::EVENT_ID,
        ));
    }

    public function testAttendeeCanGetNewBraceletAfterOldOneRevoked(): void
    {
        $old = $this->makeBracelet('BR-TEST-005', BraceletStatus::ASSIGNED, attendeeId: 7);
        $this->makeBracelet('BR-TEST-006');

        $this->service->revoke('BR-TEST-005', self::EVENT_ID);

        $result = $this->service->associate(new AssociateBraceletDTO(
            code: 'BR-TEST-006',
            attendeeId: 7,
            eventId: self::EVENT_ID,
        ));

        $this->assertSame(BraceletStatus::ASSIGNED, $result->status);
        $this->assertSame(BraceletStatus::REVOKED, $old->refresh()->status);
    }

    public function testRevokeMarksBraceletRevoked(): void
    {
        $this->makeBracelet('BR-TEST-007', BraceletStatus::ASSIGNED, attendeeId: 7);

        $result = $this->service->revoke('BR-TEST-007', self::EVENT_ID);

        $this->assertSame(BraceletStatus::REVOKED, $result->status);
        $this->assertNotNull($result->revokedAt);
    }

    public function testRevokeFailsIfAlreadyTerminal(): void
    {
        $this->makeBracelet('BR-TEST-008', BraceletStatus::REVOKED);

        $this->expectException(BraceletAssociationException::class);

        $this->service->revoke('BR-TEST-008', self::EVENT_ID);
    }

    public function testReplaceIsAtomicAndKeepsExactlyOneActiveBracelet(): void
    {
        $this->makeBracelet('BR-TEST-009', BraceletStatus::ASSIGNED, attendeeId: 7);
        $this->makeBracelet('BR-TEST-010');

        $result = $this->service->replace('BR-TEST-009', 'BR-TEST-010', self::EVENT_ID);

        $this->assertSame(BraceletStatus::ASSIGNED, $result->status);
        $this->assertSame(7, $result->attendeeId);

        $activeCount = DigitBracelet::query()
            ->where('attendee_id', 7)
            ->whereNotIn('status', [BraceletStatus::REVOKED->value, BraceletStatus::COMPROMISED->value])
            ->count();

        $this->assertSame(1, $activeCount, 'Attendee must end up with exactly one active bracelet after replace().');
    }

    public function testReplaceFailsIfOldBraceletNotCurrentlyAssigned(): void
    {
        $this->makeBracelet('BR-TEST-011', BraceletStatus::GENERATED);
        $this->makeBracelet('BR-TEST-012');

        $this->expectException(BraceletAssociationException::class);

        $this->service->replace('BR-TEST-011', 'BR-TEST-012', self::EVENT_ID);
    }
}
