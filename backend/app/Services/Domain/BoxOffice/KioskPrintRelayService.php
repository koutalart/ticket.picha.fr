<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\BoxOffice;

use HiEvents\DomainObjects\Generated\KioskPrintRelayDomainObjectAbstract;
use HiEvents\DomainObjects\KioskPrintRelayDomainObject;
use HiEvents\Exceptions\InvalidKioskPrintRelayTokenException;
use HiEvents\Repository\Interfaces\KioskPrintRelayRepositoryInterface;
use Illuminate\Support\Str;

/**
 * One relay token per event. Only its SHA-256 hash is stored: the plaintext
 * is shown once to the organizer, then lives on the venue computer.
 */
class KioskPrintRelayService
{
    private const TOKEN_LENGTH = 48;

    public function __construct(
        private readonly KioskPrintRelayRepositoryInterface $relayRepository,
    ) {}

    public function generateToken(int $eventId): string
    {
        $token = Str::random(self::TOKEN_LENGTH);

        $this->relayRepository->replaceTokenHash($eventId, $this->hash($token));

        return $token;
    }

    /**
     * @throws InvalidKioskPrintRelayTokenException
     */
    public function authenticate(?string $token): KioskPrintRelayDomainObject
    {
        if ($token === null || strlen($token) < self::TOKEN_LENGTH) {
            throw new InvalidKioskPrintRelayTokenException(__('Invalid print relay token.'));
        }

        /** @var KioskPrintRelayDomainObject|null $relay */
        $relay = $this->relayRepository->findFirstWhere([
            KioskPrintRelayDomainObjectAbstract::TOKEN_HASH => $this->hash($token),
        ]);

        if ($relay === null) {
            throw new InvalidKioskPrintRelayTokenException(__('Invalid print relay token.'));
        }

        return $relay;
    }

    public function markSeen(KioskPrintRelayDomainObject $relay): void
    {
        $this->relayRepository->updateWhere(
            [KioskPrintRelayDomainObjectAbstract::LAST_SEEN_AT => now()->toDateTimeString()],
            [KioskPrintRelayDomainObjectAbstract::ID => $relay->getId()],
        );
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
