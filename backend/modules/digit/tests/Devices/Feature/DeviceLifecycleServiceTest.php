<?php

declare(strict_types=1);

namespace Digit\Tests\Devices\Feature;

use Digit\Devices\Domain\Exceptions\DeviceTransitionNotAllowedException;
use Digit\Devices\Domain\Services\DeviceLifecycleService;
use Digit\Tests\Devices\Support\InMemorySqliteTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Covers every transition in DeviceLifecycleService::ALLOWED_TRANSITIONS
 * (9 allowed, 3 forbidden), plus the edge cases that were never exercised
 * even manually during B3/Étape 4-5 (DISABLED<->LOST direct,
 * DISABLED/LOST->REVOKED direct, REVOKED->DISABLED, REVOKED->LOST).
 * AuthenticateScanDevice.php is not touched by this file or exercised by
 * it - only the lifecycle service and its own two tables.
 */
class DeviceLifecycleServiceTest extends InMemorySqliteTestCase
{
    private DeviceLifecycleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DeviceLifecycleService();
    }

    private function createDevice(array $overrides = []): int
    {
        return DB::table('digit_scan_devices')->insertGetId(array_merge([
            'name' => 'Lifecycle Test Device',
            'account_id' => 5,
            'event_id' => 4,
            'check_in_list_id' => 3,
            'token_hash' => hash('sha256', Str::random(48)),
            'status' => 'ACTIVE',
            'revoked_at' => null,
            'all_check_in_lists' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function statusOf(int $deviceId): string
    {
        return DB::table('digit_scan_devices')->where('id', $deviceId)->value('status');
    }

    // ===================== TRANSITIONS AUTORISEES =====================

    public function testActiveToDisabled(): void
    {
        $id = $this->createDevice(['status' => 'ACTIVE']);
        $this->assertTrue($this->service->disable($id));
        $this->assertSame('DISABLED', $this->statusOf($id));
    }

    public function testDisabledToActive(): void
    {
        $id = $this->createDevice(['status' => 'DISABLED', 'revoked_at' => now()]);
        $this->assertTrue($this->service->reactivate($id));
        $this->assertSame('ACTIVE', $this->statusOf($id));
    }

    public function testActiveToLost(): void
    {
        $id = $this->createDevice(['status' => 'ACTIVE']);
        $this->assertTrue($this->service->markLost($id));
        $this->assertSame('LOST', $this->statusOf($id));
    }

    public function testLostToActive(): void
    {
        $id = $this->createDevice(['status' => 'LOST', 'revoked_at' => now()]);
        $this->assertTrue($this->service->reactivate($id));
        $this->assertSame('ACTIVE', $this->statusOf($id));
    }

    public function testDisabledToLostDirect(): void
    {
        // Never exercised even manually during B3/Étape 4-5 - always went
        // through ACTIVE in between until now.
        $id = $this->createDevice(['status' => 'DISABLED', 'revoked_at' => now()]);
        $this->assertTrue($this->service->markLost($id));
        $this->assertSame('LOST', $this->statusOf($id));
    }

    public function testLostToDisabledDirect(): void
    {
        $id = $this->createDevice(['status' => 'LOST', 'revoked_at' => now()]);
        $this->assertTrue($this->service->disable($id));
        $this->assertSame('DISABLED', $this->statusOf($id));
    }

    public function testActiveToRevoked(): void
    {
        $id = $this->createDevice(['status' => 'ACTIVE']);
        $this->assertTrue($this->service->revoke($id));
        $this->assertSame('REVOKED', $this->statusOf($id));
    }

    public function testDisabledToRevokedDirect(): void
    {
        $id = $this->createDevice(['status' => 'DISABLED', 'revoked_at' => now()]);
        $this->assertTrue($this->service->revoke($id));
        $this->assertSame('REVOKED', $this->statusOf($id));
    }

    public function testLostToRevokedDirect(): void
    {
        $id = $this->createDevice(['status' => 'LOST', 'revoked_at' => now()]);
        $this->assertTrue($this->service->revoke($id));
        $this->assertSame('REVOKED', $this->statusOf($id));
    }

    // ===================== TRANSITIONS INTERDITES =====================

    public function testRevokedToActiveIsForbidden(): void
    {
        $id = $this->createDevice(['status' => 'REVOKED', 'revoked_at' => now()]);
        $this->expectException(DeviceTransitionNotAllowedException::class);
        $this->service->reactivate($id);
    }

    public function testRevokedToDisabledIsForbidden(): void
    {
        $id = $this->createDevice(['status' => 'REVOKED', 'revoked_at' => now()]);
        $this->expectException(DeviceTransitionNotAllowedException::class);
        $this->service->disable($id);
    }

    public function testRevokedToLostIsForbidden(): void
    {
        $id = $this->createDevice(['status' => 'REVOKED', 'revoked_at' => now()]);
        $this->expectException(DeviceTransitionNotAllowedException::class);
        $this->service->markLost($id);
    }

    // ===================== CAS LIMITES =====================

    public function testNonExistentDeviceReturnsFalseWithoutException(): void
    {
        $this->assertFalse($this->service->disable(999999));
    }

    public function testAlreadyInRequestedStateReturnsFalseWithoutException(): void
    {
        $id = $this->createDevice(['status' => 'DISABLED', 'revoked_at' => now()]);
        $this->assertFalse($this->service->disable($id));
    }

    public function testOtherFieldsUnchangedAfterTransition(): void
    {
        $id = $this->createDevice([
            'name' => 'Terrain Reference Device',
            'token_hash' => hash('sha256', 'known-token'),
            'check_in_list_id' => 3,
            'event_id' => 4,
        ]);

        $this->service->disable($id);

        $device = DB::table('digit_scan_devices')->where('id', $id)->first();
        $this->assertSame('Terrain Reference Device', $device->name);
        $this->assertSame(hash('sha256', 'known-token'), $device->token_hash);
        $this->assertSame(3, $device->check_in_list_id);
        $this->assertSame(4, $device->event_id);
    }

    public function testAuditLogEntryProducedForEachTransition(): void
    {
        $id = $this->createDevice(['status' => 'ACTIVE']);

        $this->service->disable($id);

        $entries = DB::table('digit_scan_device_audit_log')->where('device_id', $id)->get();
        $this->assertCount(1, $entries);
        $this->assertSame('status_changed:ACTIVE->DISABLED', $entries[0]->action);

        $oldValue = json_decode($entries[0]->old_value, true);
        $newValue = json_decode($entries[0]->new_value, true);
        $this->assertSame('ACTIVE', $oldValue['status']);
        $this->assertSame('DISABLED', $newValue['status']);
    }
}
