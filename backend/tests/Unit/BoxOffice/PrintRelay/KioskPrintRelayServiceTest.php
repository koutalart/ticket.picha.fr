<?php

declare(strict_types=1);

namespace Tests\Unit\BoxOffice\PrintRelay;

use HiEvents\DomainObjects\KioskPrintRelayDomainObject;
use HiEvents\Exceptions\InvalidKioskPrintRelayTokenException;
use HiEvents\Repository\Interfaces\KioskPrintRelayRepositoryInterface;
use HiEvents\Services\Application\Handlers\BoxOffice\PrintRelay\GenerateKioskPrintRelayTokenHandler;
use HiEvents\Services\Domain\BoxOffice\KioskPrintRelayService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class KioskPrintRelayServiceTest extends TestCase
{
    private KioskPrintRelayRepositoryInterface&MockInterface $relayRepository;

    private KioskPrintRelayService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->relayRepository = Mockery::mock(KioskPrintRelayRepositoryInterface::class);
        $this->service = new KioskPrintRelayService($this->relayRepository);
    }

    public function test_generated_token_is_long_and_only_its_hash_is_stored(): void
    {
        $storedHash = null;
        $this->relayRepository->shouldReceive('replaceTokenHash')->once()
            ->with(5, Mockery::type('string'))
            ->andReturnUsing(function (int $eventId, string $hash) use (&$storedHash) {
                $storedHash = $hash;
            });

        $result = (new GenerateKioskPrintRelayTokenHandler($this->service))->handle(5);

        self::assertGreaterThanOrEqual(40, strlen($result->relay_token));
        self::assertNull($result->last_seen_at);
        self::assertSame(hash('sha256', $result->relay_token), $storedHash);
        self::assertStringNotContainsString($result->relay_token, $storedHash);
    }

    public function test_regenerating_replaces_the_event_token(): void
    {
        $hashes = [];
        $this->relayRepository->shouldReceive('replaceTokenHash')->twice()
            ->with(5, Mockery::type('string'))
            ->andReturnUsing(function (int $eventId, string $hash) use (&$hashes) {
                $hashes[] = $hash;
            });

        $first = $this->service->generateToken(5);
        $second = $this->service->generateToken(5);

        self::assertNotSame($first, $second);
        self::assertNotSame($hashes[0], $hashes[1]);
        self::assertSame(hash('sha256', $second), $hashes[1]);
    }

    public function test_authenticates_a_known_token_by_its_hash(): void
    {
        $token = str_repeat('a', 48);
        $relay = (new KioskPrintRelayDomainObject)->setId(1)->setEventId(5);

        $this->relayRepository->shouldReceive('findFirstWhere')->once()
            ->with(['token_hash' => hash('sha256', $token)])
            ->andReturn($relay);

        self::assertSame($relay, $this->service->authenticate($token));
    }

    public function test_rejects_an_unknown_token(): void
    {
        $this->relayRepository->shouldReceive('findFirstWhere')->once()->andReturnNull();

        $this->expectException(InvalidKioskPrintRelayTokenException::class);

        $this->service->authenticate(str_repeat('b', 48));
    }

    public function test_rejects_a_missing_or_short_token_without_querying(): void
    {
        $this->relayRepository->shouldReceive('findFirstWhere')->never();

        foreach ([null, '', 'short'] as $token) {
            try {
                $this->service->authenticate($token);
                self::fail('Expected an invalid token exception.');
            } catch (InvalidKioskPrintRelayTokenException) {
                self::assertTrue(true);
            }
        }
    }
}
