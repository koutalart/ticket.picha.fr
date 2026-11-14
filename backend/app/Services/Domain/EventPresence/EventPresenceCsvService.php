<?php

namespace HiEvents\Services\Domain\EventPresence;

use HiEvents\DomainObjects\EventPresenceDomainObject;
use HiEvents\Repository\Interfaces\EventPresenceRepositoryInterface;

class EventPresenceCsvService
{
    public function __construct(
        private readonly EventPresenceRepositoryInterface $presenceRepository,
    ) {}

    public function build(int $eventId): string
    {
        $rows = $this->presenceRepository->findWhere(['event_id' => $eventId])
            ->sortBy(fn (EventPresenceDomainObject $presence) => $presence->getId());

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['date', 'prenom', 'nom', 'telephone', 'staff'], ';');

        foreach ($rows as $presence) {
            fputcsv($handle, [
                $presence->getUpdatedAt() ?? $presence->getCreatedAt(),
                $presence->getFirstName(),
                $presence->getLastName(),
                $presence->getPhone(),
                $presence->getStaff(),
            ], ';');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
