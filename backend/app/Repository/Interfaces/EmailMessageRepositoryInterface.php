<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\EmailMessageDomainObject;
use HiEvents\Services\Domain\Email\Inbox\DTO\HistoricalOutgoingMessageDTO;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<EmailMessageDomainObject>
 */
interface EmailMessageRepositoryInterface extends RepositoryInterface
{
    /**
     * Event messages (outgoing_messages) sent before the given date that are not in the e-mail log yet, by id.
     *
     * @return Collection<int, HistoricalOutgoingMessageDTO>
     */
    public function findUnimportedOutgoingMessages(int $afterId, string $sentBefore, ?int $organizerId, int $limit): Collection;

    /**
     * Creation date of the first event message logged live, null when none was logged yet.
     */
    public function findFirstLoggedAt(string $source): ?string;
}
