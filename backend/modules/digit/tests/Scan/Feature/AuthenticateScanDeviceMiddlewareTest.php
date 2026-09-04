<?php

declare(strict_types=1);

namespace Digit\Tests\Scan\Feature;

use Digit\Scan\Http\Middleware\AuthenticateScanDevice;
use Digit\Tests\Scan\Support\InMemorySqliteTestCase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Feature tests for AuthenticateScanDevice - the single security gate for
 * every DIGIT scan device request. Exercises the real handle() method
 * (not reflection into private internals) against an in-memory DB, so
 * these tests run the exact same code path production traffic does.
 *
 * This file is additive only - AuthenticateScanDevice.php itself is
 * frozen (B2-B5) and is never modified here.
 */
class AuthenticateScanDeviceMiddlewareTest extends InMemorySqliteTestCase
{
    private const EVENT_A = 4;
    private const EVENT_B = 99;

    private function createDevice(array $overrides = []): array
    {
        $plainToken = Str::random(48);

        $id = DB::table('digit_scan_devices')->insertGetId(array_merge([
            'name' => 'Test Device',
            'account_id' => 5,
            'event_id' => self::EVENT_A,
            'check_in_list_id' => null,
            'token_hash' => hash('sha256', $plainToken),
            'revoked_at' => null,
            'status' => 'ACTIVE',
            'all_check_in_lists' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return ['id' => $id, 'token' => $plainToken];
    }

    private function createCheckInList(int $id, string $shortId, int $eventId): void
    {
        DB::table('check_in_lists')->insert([
            'id' => $id,
            'short_id' => $shortId,
            'event_id' => $eventId,
        ]);
    }

    private function assignPivot(int $deviceId, int $checkInListId): void
    {
        DB::table('digit_scan_device_check_in_lists')->insert([
            'device_id' => $deviceId,
            'check_in_list_id' => $checkInListId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Calls the real middleware exactly as the HTTP kernel would, with a
     * route bound to the given check-in list short_id (explicitly set to
     * null when the endpoint under test doesn't scope by list, overriding
     * whatever Route::bind() may have auto-extracted from the URI).
     */
    private function callMiddleware(?string $token, ?string $checkInListShortId): Response
    {
        $request = Request::create('/digit/scan/test', 'POST');

        if ($token !== null) {
            $request->headers->set('Authorization', 'Bearer ' . $token);
        }

        $route = new Route('POST', '/digit/scan/{check_in_list_short_id?}', []);
        $route->bind($request);
        $route->setParameter('check_in_list_short_id', $checkInListShortId);
        $request->setRouteResolver(fn () => $route);

        $middleware = new AuthenticateScanDevice();

        return $middleware->handle($request, fn (Request $req) => response()->json(['ok' => true], 200));
    }

    // ===================== AUTHENTIFICATION TOKEN =====================

    public function testValidTokenAuthenticatesDevice(): void
    {
        $device = $this->createDevice();
        $this->createCheckInList(3, 'cil_valid', self::EVENT_A);
        DB::table('digit_scan_devices')->where('id', $device['id'])->update(['check_in_list_id' => 3]);

        $response = $this->callMiddleware($device['token'], 'cil_valid');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testMissingTokenIsRejected(): void
    {
        $response = $this->callMiddleware(null, null);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testWrongTokenIsRejected(): void
    {
        $this->createDevice();

        $response = $this->callMiddleware('this-token-does-not-exist-anywhere', null);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testHashedTokenMatchingStoredHashIsAccepted(): void
    {
        // Explicit check that authentication is driven by the SHA-256
        // hash comparison, not a plaintext comparison - only the hash is
        // ever stored, so this proves the lookup hashes the presented
        // token before comparing.
        $plainToken = 'a-known-plaintext-token-value';
        DB::table('digit_scan_devices')->insert([
            'name' => 'Hash Test Device',
            'account_id' => 5,
            'event_id' => self::EVENT_A,
            'token_hash' => hash('sha256', $plainToken),
            'status' => 'ACTIVE',
            'all_check_in_lists' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->callMiddleware($plainToken, null);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testRevokedTokenIsRejected(): void
    {
        $device = $this->createDevice(['revoked_at' => now(), 'status' => 'REVOKED']);

        $response = $this->callMiddleware($device['token'], null);

        $this->assertSame(401, $response->getStatusCode());
    }

    // ===================== SCOPE CHECK-IN LIST =====================

    public function testDeviceWithPivotToRequestedListIsAccepted(): void
    {
        $device = $this->createDevice();
        $this->createCheckInList(10, 'cil_10', self::EVENT_A);
        $this->assignPivot($device['id'], 10);

        $response = $this->callMiddleware($device['token'], 'cil_10');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDeviceWithPivotToAnotherListIsRejected(): void
    {
        $device = $this->createDevice();
        $this->createCheckInList(11, 'cil_11', self::EVENT_A);
        $this->createCheckInList(12, 'cil_12', self::EVENT_A);
        $this->assignPivot($device['id'], 11);

        $response = $this->callMiddleware($device['token'], 'cil_12');

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testDeviceWithMultipleListsAcceptedOnlyOnAuthorizedOnes(): void
    {
        $device = $this->createDevice();
        $this->createCheckInList(20, 'cil_20', self::EVENT_A);
        $this->createCheckInList(21, 'cil_21', self::EVENT_A);
        $this->createCheckInList(22, 'cil_22', self::EVENT_A);
        $this->assignPivot($device['id'], 20);
        $this->assignPivot($device['id'], 21);

        $this->assertSame(200, $this->callMiddleware($device['token'], 'cil_20')->getStatusCode());
        $this->assertSame(200, $this->callMiddleware($device['token'], 'cil_21')->getStatusCode());
        $this->assertSame(401, $this->callMiddleware($device['token'], 'cil_22')->getStatusCode());
    }

    public function testAllCheckInListsGrantsGlobalAccessWithinSameEvent(): void
    {
        $device = $this->createDevice(['all_check_in_lists' => true]);
        $this->createCheckInList(30, 'cil_30', self::EVENT_A);
        $this->createCheckInList(31, 'cil_31', self::EVENT_A);

        $this->assertSame(200, $this->callMiddleware($device['token'], 'cil_30')->getStatusCode());
        $this->assertSame(200, $this->callMiddleware($device['token'], 'cil_31')->getStatusCode());
    }

    public function testPivotExistsButRequestedListAbsentDoesNotFallBackToUnlimitedAccess(): void
    {
        // Regression test for the real bug found and fixed in Étape 4:
        // a device with check_in_list_id=null but at least one pivot row
        // must NOT be treated as "unrestricted" just because the specific
        // requested list isn't one of its assigned ones.
        $device = $this->createDevice(['check_in_list_id' => null]);
        $this->createCheckInList(40, 'cil_40', self::EVENT_A);
        $this->createCheckInList(41, 'cil_41', self::EVENT_A);
        $this->assignPivot($device['id'], 40);

        $response = $this->callMiddleware($device['token'], 'cil_41');

        $this->assertSame(401, $response->getStatusCode());
    }

    // ===================== CAS LIMITES =====================

    public function testNonExistentDeviceTokenIsRejected(): void
    {
        // No device row created at all - mechanically identical to
        // "wrong token" but kept as its own named test for documentation.
        $response = $this->callMiddleware(Str::random(48), null);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testDisabledDeviceIsRejected(): void
    {
        $device = $this->createDevice(['status' => 'DISABLED', 'revoked_at' => now()]);

        $response = $this->callMiddleware($device['token'], null);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testLostDeviceIsRejected(): void
    {
        $device = $this->createDevice(['status' => 'LOST', 'revoked_at' => now()]);

        $response = $this->callMiddleware($device['token'], null);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testRevokedDeviceIsRejected(): void
    {
        $device = $this->createDevice(['status' => 'REVOKED', 'revoked_at' => now()]);

        $response = $this->callMiddleware($device['token'], null);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testNonExistentCheckInListIsRejected(): void
    {
        $device = $this->createDevice();

        $response = $this->callMiddleware($device['token'], 'cil_does_not_exist');

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testDifferentEventBetweenDeviceAndCheckInListIsRejected(): void
    {
        $device = $this->createDevice(['all_check_in_lists' => true, 'event_id' => self::EVENT_A]);
        $this->createCheckInList(50, 'cil_other_event', self::EVENT_B);

        $response = $this->callMiddleware($device['token'], 'cil_other_event');

        $this->assertSame(401, $response->getStatusCode());
    }
}
