<?php

declare(strict_types=1);

namespace Digit\Tests\Bracelets\Feature;

use Digit\Bracelets\Domain\Enums\BraceletStatus;
use Digit\Bracelets\Domain\Exceptions\BraceletEventMismatchException;
use Digit\Bracelets\Domain\Exceptions\BraceletNotAssignedException;
use Digit\Bracelets\Domain\Exceptions\BraceletNotFoundException;
use Digit\Bracelets\Domain\Exceptions\InvalidBraceletPayloadException;
use Digit\Bracelets\Domain\Models\DigitBracelet;
use Digit\Bracelets\Domain\Services\BraceletLookupService;
use Digit\Bracelets\Domain\Services\BraceletSignatureService;
use Digit\Bracelets\Security\EventSecurityKeyService;
use Digit\Bracelets\Security\Models\DigitEventSecurityKey;
use Digit\Tests\Bracelets\Support\InMemorySqliteTestCase;

/**
 * Verifies the exact validation order from the security design: signature
 * check first (no DB read of digit_bracelets on failure), then lookup,
 * then event match, then status. This is the interface the future scanner
 * (Phase 2) will call - NOT yet wired into ScanCoordinatorService.
 */
class BraceletLookupServiceTest extends InMemorySqliteTestCase
{
    private const EVENT_A = 1;
    private const EVENT_B = 2;

    private BraceletLookupService $lookupService;
    private BraceletSignatureService $signatureService;
    private EventSecurityKeyService $keyService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signatureService = new BraceletSignatureService();
        $this->keyService = new EventSecurityKeyService();
        $this->lookupService = new BraceletLookupService($this->signatureService, $this->keyService);
    }

    private function secretFor(int $eventId): string
    {
        return $this->keyService->getOrCreateSecret($eventId);
    }

    private function makeBracelet(string $code, int $eventId, BraceletStatus $status, ?int $attendeeId = null): DigitBracelet
    {
        return DigitBracelet::query()->create([
            'code' => $code,
            'event_id' => $eventId,
            'account_id' => 1,
            'attendee_id' => $attendeeId,
            'status' => $status->value,
        ]);
    }

    public function testValidAssignedBraceletResolvesToAttendeeId(): void
    {
        $secret = $this->secretFor(self::EVENT_A);
        $this->makeBracelet('BR-LOOKUP-001', self::EVENT_A, BraceletStatus::ASSIGNED, attendeeId: 55);

        $payload = $this->signatureService->buildPayload('BR-LOOKUP-001', $secret);

        $attendeeId = $this->lookupService->resolveAttendeeId($payload, self::EVENT_A);

        $this->assertSame(55, $attendeeId);
    }

    public function testTamperedSignatureRejectedBeforeAnyBraceletLookup(): void
    {
        $secret = $this->secretFor(self::EVENT_A);
        // Deliberately do NOT create a matching bracelet row - if the
        // implementation ever queried the DB before checking the
        // signature, this would throw BraceletNotFoundException instead,
        // which would prove the ordering is wrong.
        $payload = $this->signatureService->buildPayload('BR-DOES-NOT-EXIST', $secret);
        $tampered = substr($payload, 0, -1) . 'X';

        $this->expectException(InvalidBraceletPayloadException::class);
        $this->lookupService->resolveAttendeeId($tampered, self::EVENT_A);
    }

    public function testUnknownCodeIsRejectedAsNotFound(): void
    {
        $secret = $this->secretFor(self::EVENT_A);
        $payload = $this->signatureService->buildPayload('BR-NEVER-CREATED', $secret);

        $this->expectException(BraceletNotFoundException::class);
        $this->lookupService->resolveAttendeeId($payload, self::EVENT_A);
    }

    public function testBraceletFromAnotherEventIsRejected(): void
    {
        $secretB = $this->secretFor(self::EVENT_B);
        $this->makeBracelet('BR-LOOKUP-002', self::EVENT_B, BraceletStatus::ASSIGNED, attendeeId: 10);

        // Signed correctly for event B, but presented to a device
        // authenticated for event A.
        $payload = $this->signatureService->buildPayload('BR-LOOKUP-002', $secretB);
        $this->secretFor(self::EVENT_A);

        // Since the device only knows event A's secret, signing with
        // event B's secret and verifying against event A's would already
        // fail signature verification (each event has its own key) -
        // this confirms per-event key scoping is itself a first line of
        // defense before the explicit event_id check even runs.
        $this->expectException(InvalidBraceletPayloadException::class);
        $this->lookupService->resolveAttendeeId($payload, self::EVENT_A);
    }

    public function testNonAssignedBraceletIsRejectedWithStatusReason(): void
    {
        $secret = $this->secretFor(self::EVENT_A);
        $this->makeBracelet('BR-LOOKUP-003', self::EVENT_A, BraceletStatus::REVOKED, attendeeId: 20);
        $payload = $this->signatureService->buildPayload('BR-LOOKUP-003', $secret);

        try {
            $this->lookupService->resolveAttendeeId($payload, self::EVENT_A);
            $this->fail('Expected BraceletNotAssignedException');
        } catch (BraceletNotAssignedException $e) {
            $this->assertSame(BraceletStatus::REVOKED, $e->actualStatus);
            $this->assertSame('bracelet_not_assigned:REVOKED', $e->reason());
        }
    }

    public function testEventMismatchDetectedEvenWhenSecretAccidentallyShared(): void
    {
        // Defense-in-depth path: forces the same secret onto two events
        // (a misconfiguration that should never happen via normal usage,
        // since getOrCreateSecret() always generates a fresh random
        // secret per event) to prove the explicit event_id check still
        // catches a cross-event bracelet even if per-event key scoping
        // were ever compromised.
        $sharedSecret = 'accidentally-shared-secret';

        DigitEventSecurityKey::query()->create(['event_id' => self::EVENT_A, 'secret' => $sharedSecret, 'created_at' => now()]);
        DigitEventSecurityKey::query()->create(['event_id' => self::EVENT_B, 'secret' => $sharedSecret, 'created_at' => now()]);

        $this->makeBracelet('BR-LOOKUP-004', self::EVENT_B, BraceletStatus::ASSIGNED, attendeeId: 30);
        $payload = $this->signatureService->buildPayload('BR-LOOKUP-004', $sharedSecret);

        $this->expectException(BraceletEventMismatchException::class);
        $this->lookupService->resolveAttendeeId($payload, self::EVENT_A);
    }

    public function testMissingSecurityKeyIsRejectedLikeInvalidSignature(): void
    {
        // No getOrCreateSecret() call for this event - simulates an
        // operator who forgot to generate/initialize bracelets first.
        $payload = 'DGT1.BR-WHATEVER.somesignature';

        $this->expectException(InvalidBraceletPayloadException::class);
        $this->lookupService->resolveAttendeeId($payload, 999);
    }
}
